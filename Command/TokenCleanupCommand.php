<?php
namespace Areanet\PIM\Command;

use Areanet\PIM\Classes\Config\Adapter;
use Knp\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Raeumt abgelaufene Anmeldetoken aus `pim_token` (000-000-0015).
 *
 * ── Wogegen ──────────────────────────────────────────────────────────────────────────
 *
 * Jede Anmeldung legt eine Zeile an. Aufgeraeumt wurde bisher nur TRAEGE, in
 * `BaseControllerProvider::checkToken()`: Wird ein abgelaufener Token noch einmal vorgezeigt,
 * verschwindet er. Ein Token, den niemand wieder benutzt — der Normalfall beim Schliessen des
 * Browsers — blieb fuer immer. Es gab keinen Aufraeumlauf, kein Command und keinen Endpunkt.
 *
 * ── Warum das nicht auf Epic 013 warten kann ─────────────────────────────────────────
 *
 * Der Task liess offen, ob 013-003 die Tokentabelle durch JWT ersetzt. Tut es nicht: Die Story
 * behaelt den opaquen DB-Token ausdruecklich als REFRESH-Token — "genau der Mechanismus, der
 * ohnehin existiert". Die Tabelle bleibt also, und mit ihr das Wachstum.
 *
 * ── Was als abgelaufen gilt ──────────────────────────────────────────────────────────
 *
 * Dieselbe Rechnung wie in checkToken(), damit hier nichts faellt, was dort noch gueltig
 * waere:
 *
 *   - `modified` aelter als das Zeitlimit. Gezaehlt wird ab der letzten Benutzung, nicht ab
 *     der Anmeldung — checkToken() schreibt `modified` bei jedem Aufruf zurueck.
 *   - Das Limit kommt aus der Gruppe des Benutzers (`tokenTimeout`, in Minuten), sonst aus
 *     `APP_TOKEN_TIMEOUT` (in Sekunden).
 *   - Ein Token MIT `referrer` ist ein API-Token und verfaellt nicht. checkToken() nimmt ihn
 *     ebenfalls aus; er wird ueber `deleteToken` entfernt, nicht ueber die Zeit.
 *   - Ist `APP_CHECK_TOKEN_TIMEOUT` aus, verfaellt gar nichts — dann raeumt dieses Command
 *     auch nichts weg, sonst loeschte es gueltige Sitzungen.
 *
 * ── --dry-run ────────────────────────────────────────────────────────────────────────
 *
 * Ein Aufraeumlauf, den man nicht vorher ansehen kann, wird nicht ausgefuehrt. Der Vorgabewert
 * ist deshalb bewusst NICHT trocken — wer das Command in einen Cron haengt, soll nicht
 * feststellen, dass es nie etwas getan hat.
 */
class TokenCleanupCommand extends Command
{
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('appcms:token:cleanup')
            ->setDescription('Entfernt abgelaufene Anmeldetoken aus pim_token')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur zaehlen, nichts loeschen')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $app = $this->getSilexApplication();
        $em  = $app['orm.em'];

        if (!Adapter::getConfig()->APP_CHECK_TOKEN_TIMEOUT) {
            $output->writeln('<comment>APP_CHECK_TOKEN_TIMEOUT ist aus — Token verfallen nicht, es wird nichts entfernt.</comment>');

            return 0;
        }

        $trocken = (bool) $input->getOption('dry-run');
        $jetzt   = new \DateTime();
        $standard = (int) Adapter::getConfig()->APP_TOKEN_TIMEOUT;

        $token = $em->createQuery(
            "SELECT t FROM Areanet\\PIM\\Entity\\Token t WHERE t.referrer IS NULL OR t.referrer = ''"
        )->getResult();

        $abgelaufen = 0;
        $geprueft   = 0;

        foreach ($token as $eintrag) {
            $geprueft++;

            $benutzer = $eintrag->getUser();
            $limit    = $standard;

            if ($benutzer && ($gruppe = $benutzer->getGroup()) && $gruppe->getTokenTimeout()) {
                $limit = ((int) $gruppe->getTokenTimeout()) * 60;
            }

            if (!$limit) {
                continue;
            }

            if (($jetzt->getTimestamp() - $eintrag->getModified()->getTimestamp()) <= $limit) {
                continue;
            }

            $abgelaufen++;

            if (!$trocken) {
                $em->remove($eintrag);
            }
        }

        if (!$trocken) {
            $em->flush();
        }

        $output->writeln(sprintf(
            '%s %d von %d Anmeldetoken abgelaufen%s.',
            $trocken ? '→' : '✓',
            $abgelaufen,
            $geprueft,
            $trocken ? ' (--dry-run, nichts entfernt)' : ' und entfernt'
        ));

        return 0;
    }
}
