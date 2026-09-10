<?php
namespace Areanet\PIM\Command;

use Areanet\PIM\Classes\Kernel\Command;
use Areanet\PIM\Classes\Security\Feldverschluesselung;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Bringt verschluesselte Feldwerte vom alten AES-CBC-Format auf XChaCha20-Poly1305
 * (010-004-0003).
 *
 * ── Wozu ─────────────────────────────────────────────────────────────────────────────
 *
 * Seit 010-004-0002 schreibt das Framework AEAD und liest beides. Ein Bestandswert bleibt also
 * lesbar und wird beim naechsten Schreiben nebenbei umgestellt. Wer nicht warten will, bis
 * jeder Datensatz einmal angefasst wurde, laesst diesen Befehl laufen.
 *
 * ── --dry-run ist keine Zierde ───────────────────────────────────────────────────────
 *
 * Der Befehl laeuft spaeter in fremden Bestandsprojekten auf deren Daten (Epic 007). Eine
 * Migration, die man nicht vorher ansehen kann, wird zu Recht nicht ausgefuehrt. Der
 * Trockenlauf zaehlt, was er taete, und fasst nichts an.
 *
 * ── Der Rueckweg ─────────────────────────────────────────────────────────────────────
 *
 * VOR DEM LAUF EINE SICHERUNG DER DATENBANK ANLEGEN. Das ist der Rueckweg, und er ist der
 * einzige: Umgeschluesselte Werte lassen sich nicht zurueckrechnen, ohne den alten Schluessel
 * erneut anzuwenden — und genau davon soll man wegkommen.
 *
 * ABER: Ein ABGEBROCHENER Lauf ist kein Schaden. Beide Formate bleiben lesbar, und der Befehl
 * ueberspringt, was schon umgestellt ist. Wer nach einem Fehler noch einmal startet, macht dort
 * weiter, wo es aufgehoert hat. Der Rueckweg wird also nur gebraucht, wenn jemand mit dem
 * FALSCHEN SECURITY_CIPHER_KEY gelaufen ist — dann sind die Werte nicht kaputt, aber mit einem
 * Schluessel verschluesselt, den niemand wollte.
 *
 * ── Warum DBAL und nicht der EntityManager ───────────────────────────────────────────
 *
 * Der Befehl arbeitet auf Spalten, nicht auf Objekten. Ueber den EntityManager muesste er jede
 * Entity laden, haette Lifecycle-Callbacks am Hals (`Base::updateModifiedDatetime()` wuerde
 * `modified` fortschreiben, obwohl sich fachlich nichts aendert) und den ganzen Bestand im
 * Speicher. Ueber DBAL sind es Stapel fester Groesse und ein UPDATE je Zeile.
 *
 * ── Wie die Felder gefunden werden ───────────────────────────────────────────────────
 *
 * Ueber das Schema, nicht ueber eine feste Liste — sonst faende er die Felder eines Projekts
 * nicht, und genau die sind der Grund fuer den Befehl. Im Framework selbst gibt es heute
 * KEIN Feld mit `encoded: true`; ein Lauf hier meldet folgerichtig nichts zu tun.
 */
class ReencryptCommand extends Command
{
    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('appcms:security:reencrypt')
            ->setDescription('Bringt verschluesselte Feldwerte auf das AEAD-Format')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur zaehlen, nichts schreiben')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Zeilen je Stapel', '500')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app     = $this->anwendung();
        $trocken = (bool) $input->getOption('dry-run');
        $stapel  = max(1, (int) $input->getOption('batch'));

        $felder = $this->betroffeneFelder($app['schema'], $app['orm.em'], $app['helper']);

        if (!$felder) {
            $output->writeln('<comment>Kein Feld mit encoded: true — es gibt nichts umzuschluesseln.</comment>');

            return 0;
        }

        if ($trocken) {
            $output->writeln('<comment>--dry-run: es wird nichts geschrieben.</comment>');
        }

        $summe = array('geprueft' => 0, 'umgeschluesselt' => 0, 'uebersprungen' => 0);

        foreach ($felder as $feld) {
            $ergebnis = $this->spalteUmschluesseln(
                $app['db'],
                $feld['tabelle'],
                $feld['spalte'],
                $feld['schluesselspalte'],
                $trocken,
                $stapel
            );

            $output->writeln(sprintf(
                '  %-40s %5d geprueft, %5d umgeschluesselt, %5d schon neu',
                $feld['entity'].'::'.$feld['feld'],
                $ergebnis['geprueft'],
                $ergebnis['umgeschluesselt'],
                $ergebnis['uebersprungen']
            ));

            foreach ($summe as $k => $v) {
                $summe[$k] = $v + $ergebnis[$k];
            }
        }

        $output->writeln(sprintf(
            '%s %d Werte geprueft, %d umgeschluesselt, %d waren schon im neuen Format.',
            $trocken ? '→' : '✓',
            $summe['geprueft'],
            $summe['umgeschluesselt'],
            $summe['uebersprungen']
        ));

        return 0;
    }

    /**
     * Die Felder mit `encoded: true`, aufgeloest auf Tabelle und Spalte.
     *
     * OEFFENTLICH, DAMIT ES PRUEFBAR IST. Im Baum gibt es kein solches Feld — ein Test, der den
     * Befehl von aussen aufruft, koennte also nur bestaetigen, dass er nichts tut. Mit einem
     * uebergebenen Schema laesst sich pruefen, dass er das Richtige faende.
     *
     * @param array<string,array<string,mixed>> $schema
     *
     * @return array<int,array{entity:string,feld:string,tabelle:string,spalte:string,schluesselspalte:string}>
     */
    public function betroffeneFelder(array $schema, EntityManagerInterface $em, $helper): array
    {
        $felder = array();

        foreach ($schema as $entity => $angaben) {
            if ($entity === '_hash' || empty($angaben['properties'])) {
                continue;
            }

            foreach ($angaben['properties'] as $feld => $eigenschaft) {
                if (empty($eigenschaft['encoded'])) {
                    continue;
                }

                $klasse    = $helper->getFullEntityName($entity);
                $metadaten = $em->getClassMetadata($klasse);

                $felder[] = array(
                    'entity'           => $entity,
                    'feld'             => $feld,
                    'tabelle'          => $metadaten->getTableName(),
                    'spalte'           => $metadaten->getColumnName($feld),
                    'schluesselspalte' => $metadaten->getSingleIdentifierColumnName(),
                );
            }
        }

        return $felder;
    }

    /**
     * Schluesselt eine Spalte um, in Stapeln.
     *
     * OEFFENTLICH AUS DEMSELBEN GRUND wie oben: Ohne ein Feld mit `encoded: true` im Baum ist
     * dies die einzige Stelle, an der sich der Vorgang gegen eine echte Datenbank belegen
     * laesst.
     *
     * EIN STAPEL IST EINE TRANSAKTION. Bricht er ab, ist kein halb umgeschluesselter Stapel
     * zurueckgeblieben. Ueber Stapel hinweg ist ein Abbruch ohnehin unschaedlich — was schon
     * umgestellt ist, wird beim naechsten Lauf uebersprungen.
     *
     * @return array{geprueft:int,umgeschluesselt:int,uebersprungen:int}
     */
    public function spalteUmschluesseln(
        Connection $db,
        string $tabelle,
        string $spalte,
        string $schluesselspalte,
        bool $trocken,
        int $stapel
    ): array {
        $krypto  = new Feldverschluesselung();
        $zaehler = array('geprueft' => 0, 'umgeschluesselt' => 0, 'uebersprungen' => 0);
        $letzte  = null;

        while (true) {
            $abfrage = sprintf(
                'SELECT %1$s AS pk, %2$s AS wert FROM %3$s WHERE %2$s IS NOT NULL AND %2$s <> \'\'%4$s ORDER BY %1$s ASC LIMIT %5$d',
                $db->quoteIdentifier($schluesselspalte),
                $db->quoteIdentifier($spalte),
                $db->quoteIdentifier($tabelle),
                $letzte === null ? '' : sprintf(' AND %s > ?', $db->quoteIdentifier($schluesselspalte)),
                $stapel
            );

            $zeilen = $db->fetchAllAssociative($abfrage, $letzte === null ? array() : array($letzte));

            if (!$zeilen) {
                break;
            }

            $db->beginTransaction();

            try {
                foreach ($zeilen as $zeile) {
                    $letzte = $zeile['pk'];
                    $zaehler['geprueft']++;

                    if ($krypto->istNeuesFormat((string) $zeile['wert'])) {
                        $zaehler['uebersprungen']++;
                        continue;
                    }

                    $klartext = $krypto->entschluesseln((string) $zeile['wert']);

                    if ($klartext === false) {
                        throw new \RuntimeException(sprintf(
                            'Der Wert in %s.%s (%s = %s) laesst sich nicht entschluesseln. '
                            .'Steht der richtige SECURITY_CIPHER_KEY in custom/config.php?',
                            $tabelle,
                            $spalte,
                            $schluesselspalte,
                            (string) $zeile['pk']
                        ));
                    }

                    $zaehler['umgeschluesselt']++;

                    if (!$trocken) {
                        $db->update(
                            $tabelle,
                            array($spalte => $krypto->verschluesseln($klartext)),
                            array($schluesselspalte => $zeile['pk'])
                        );
                    }
                }

                $db->commit();
            } catch (\Throwable $fehler) {
                $db->rollBack();

                throw $fehler;
            }

            if (count($zeilen) < $stapel) {
                break;
            }
        }

        return $zaehler;
    }
}
