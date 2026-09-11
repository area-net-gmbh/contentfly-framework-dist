<?php
namespace Areanet\PIM\Command;

use Areanet\PIM\Classes\Kernel\Command;
use Areanet\PIM\Classes\Security\Bestandspruefung;
use Areanet\PIM\Entity\User;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Haelt die bereitgestellten Benutzer gegen ihr Fremdsystem (013-005-0002).
 *
 * ── Wogegen ──────────────────────────────────────────────────────────────────────────
 *
 * Wer aus dem Verzeichnis verschwindet, kommt nicht mehr herein — das ergibt sich von selbst:
 * Der Provider findet ihn nicht und lehnt ab. Sein Contentfly-Konto bleibt aber, und mit ihm
 *
 *   - ein Refresh-Token, das bis zu seinem Zeitlimit weiter frische Access-JWT holt,
 *   - ein opaques Anmeldetoken, das bis zum Timeout gilt.
 *
 * Ein Benutzer, den die Personalabteilung aus dem AD genommen hat, arbeitet also weiter — bis
 * ein Zeitlimit ablaeuft, das niemand dafuer gewaehlt hat.
 *
 * ── Gewaehlt: sperren, nicht loeschen ────────────────────────────────────────────────
 *
 * `isActive` auf false. Drei Gruende:
 *
 *   Es wirkt sofort.   Der `Benutzerlader` weist einen inaktiven Benutzer ab (013-002-0001),
 *                      und zwar auf JEDEM Weg — laufende Access-JWT fallen mit, weil der
 *                      Benutzer bei jedem Request geladen wird. Auch der Refresh-Endpunkt
 *                      lehnt ab (013-003-0002).
 *   Es ist umkehrbar.  Wer versehentlich aus einer Gruppe fiel, wird wieder aktiviert.
 *                      Eine geloeschte Zeile kommt nicht zurueck.
 *   `pim_log` behaelt  seinen Bezug. Ein Protokoll, dessen Benutzer verschwunden ist, kann
 *                      nicht mehr sagen, wer gehandelt hat.
 *
 * Ausdruecklich NICHT gewaehlt: „ignorieren" — das ist der Zustand, gegen den dieses Command
 * gebaut ist.
 *
 * ── Warum ein Command und kein Nebenher ──────────────────────────────────────────────
 *
 * Ein verschwundener Benutzer meldet sich gerade nicht an; das ist ja der Punkt. Es braucht
 * einen Anlass. Die Alternative waere, den Abgleich an die naechste Anmeldung EINES ANDEREN
 * Benutzers zu haengen — das verteilt fremde Arbeit auf einen Request, der davon nichts weiss,
 * und macht die Anmeldezeit von der Groesse des Verzeichnisses abhaengig.
 *
 * ── Was er nicht anfasst ─────────────────────────────────────────────────────────────
 *
 * Benutzer ohne `loginManager` — die haben ein lokales Passwort und gehen kein Fremdsystem
 * etwas an. Und Provider, die keine `Bestandspruefung` sind: Sie werden gezaehlt und
 * uebersprungen, sichtbar in der Ausgabe.
 */
class ProviderAbgleichCommand extends Command
{
    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('appcms:provider:abgleich')
            ->setDescription('Sperrt Benutzer, die ihr Fremdsystem nicht mehr kennt')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur zaehlen, nichts sperren')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app         = $this->anwendung();
        $em          = $app['orm.em'];
        $verzeichnis = $app['anmeldeanbieter'];
        $trocken     = (bool) $input->getOption('dry-run');

        $benutzer = $em->createQuery(
            "SELECT u FROM Areanet\\PIM\\Entity\\User u
              WHERE u.loginManager IS NOT NULL AND u.loginManager <> '' AND u.isActive = true"
        )->getResult();

        $geprueft = 0;
        $gesperrt = 0;
        $unklar   = 0;
        $ohnePruefung = array();

        foreach ($benutzer as $eintrag) {
            $anbietername = (string) $eintrag->getLoginManager();
            $anbieter     = $verzeichnis->holen($anbietername);

            if (!$anbieter instanceof Bestandspruefung) {
                /*
                 * Zwei Faelle, ein Ergebnis: Der Anbieter ist nicht eingetragen (etwa weil ein
                 * Projekt ihn umbenannt hat), oder er kann die Frage nicht beantworten. Beide
                 * werden GENANNT und nicht stillschweigend uebergangen — ein Abgleich, der
                 * Konten auslaesst, ohne es zu sagen, ist schlimmer als keiner.
                 */
                $ohnePruefung[$anbietername] = ($ohnePruefung[$anbietername] ?? 0) + 1;
                continue;
            }

            $geprueft++;

            $bekannt = $anbieter->kenntKennung((string) $eintrag->getExternalId());

            if ($bekannt === null) {
                // "Weiss ich gerade nicht" — dann wird nichts angefasst.
                $unklar++;
                continue;
            }

            if ($bekannt === true) {
                continue;
            }

            $gesperrt++;

            if (!$trocken) {
                $eintrag->setIsActive(false);
            }
        }

        if (!$trocken) {
            $em->flush();
        }

        $output->writeln(sprintf(
            '%s %d von %d bereitgestellten Benutzern kennt das Fremdsystem nicht mehr%s.',
            $trocken ? '→' : '✓',
            $gesperrt,
            $geprueft,
            $trocken ? ' (--dry-run, nichts gesperrt)' : ' und wurden gesperrt'
        ));

        if ($unklar > 0) {
            $output->writeln(sprintf(
                '<comment>%d Benutzer uebersprungen: Das Fremdsystem konnte keine Auskunft geben. '
                .'Ein Ausfall sperrt niemanden.</comment>',
                $unklar
            ));
        }

        foreach ($ohnePruefung as $name => $anzahl) {
            $output->writeln(sprintf(
                '<comment>%d Benutzer von "%s" uebersprungen: kein eingetragener Anbieter, oder '
                .'er kann den Bestand nicht pruefen.</comment>',
                $anzahl,
                $name
            ));
        }

        return 0;
    }
}
