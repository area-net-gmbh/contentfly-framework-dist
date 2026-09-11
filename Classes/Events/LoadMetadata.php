<?php
namespace Areanet\PIM\Classes\Events;

use Doctrine\ORM\Mapping\Builder\ClassMetadataBuilder;

/**
 * Haengt an jede Entity einen Index auf `modified`.
 *
 * WOFUER: Die Sync-Endpunkte (`/api/all`, `/api/deleted`) filtern nach `modified`. Ohne Index
 * ist das ein Tabellenscan je Abfrage — auf einer Tabelle, die mit den Daten waechst.
 *
 * ── Eine Entity OHNE `modified` wird uebersprungen (000-000-0028) ─────────────────────
 *
 * Bis hierhin hat der Listener den Index BEDINGUNGSLOS angehaengt. Fehlte die Spalte, scheiterte
 * schon die Installation:
 *
 *     Die Installation ist fehlgeschlagen: There is no column with name "modified" on table
 *     "pim_revoked_token".
 *
 * Die Meldung sagt, WAS fehlt, aber nicht, WER es verlangt — und dieser Listener steht an einer
 * Stelle, an der niemand sucht, der gerade eine neue Entity geschrieben hat. Gefunden bei
 * `013-003-0003`, und der Weg dorthin kostete eine halbe Stunde.
 *
 * GEWAEHLT: ueberspringen, nicht werfen. Drei Gruende:
 *
 *   1. Der Index hat einen Zweck, und der ist an die Spalte gebunden. Eine Entity ohne
 *      `modified` nimmt an keiner Sync-Abfrage teil — ein Index fuer sie waere sinnlos, kein
 *      Verlust.
 *   2. Eine Fehlkonfiguration waere das nicht. Es gibt keinen Grund, eine Installation
 *      abzubrechen, weil ein Projekt eine Entity ohne Zeitstempel angelegt hat; das ist sein
 *      gutes Recht.
 *   3. **Fuer den Bestand aendert es nichts.** Nachgemessen an einer frischen Installation:
 *      16 Tabellen trugen den Index vorher, 15 danach — und der Unterschied ist genau
 *      `pim_revoked_token`, dessen `modified`-Spalte mit demselben Task gefallen ist, weil sie
 *      nur existierte, um diesen Listener zufriedenzustellen. Jede andere Tabelle ist
 *      unveraendert.
 *
 * NICHT GEWAEHLT: werfen mit einer Meldung, die diesen Listener benennt. Das waere ehrlicher
 * gewesen als die Doctrine-Meldung, haette aber weiterhin eine Installation abgebrochen, fuer
 * die es keinen Grund gibt.
 *
 * Dass Entities einen `modified`-Zeitstempel mitbringen sollen, steht jetzt in
 * `an_project/docs/dev-guide.md` — an der Stelle, an der jemand nachsieht, der eine Entity
 * schreibt.
 */
class LoadMetadata
{
    public function loadClassMetadata(\Doctrine\ORM\Event\LoadClassMetadataEventArgs $eventArgs): void
    {
        $em             = $eventArgs->getEntityManager();
        $classMetadata  = $eventArgs->getClassMetadata();
        $className      = $classMetadata->getName();

        /*
         * Baeume sind ausgenommen, seit es den Listener gibt: `BaseTree` und `BaseI18nTree`
         * bringen eigene Indizes mit, und ein zusaetzlicher waere dort doppelt.
         */
        if (in_array('Areanet\PIM\Entity\BaseTree', $classMetadata->parentClasses)
            || in_array('Areanet\PIM\Entity\BaseI18nTree', $classMetadata->parentClasses)) {
            return;
        }

        // Siehe Klassenkommentar: kein Feld, kein Index — und kein Abbruch.
        if (!$classMetadata->hasField('modified')) {
            return;
        }

        $cmBuilder = new ClassMetadataBuilder($classMetadata);
        $cmBuilder->addIndex(array('modified'), 'modified_index');

        $em->getMetadataFactory()->setMetadataFor($className, $classMetadata);
    }
}
