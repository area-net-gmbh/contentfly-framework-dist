<?php
namespace Areanet\PIM\Classes;

use Areanet\PIM\Entity\User;
use Areanet\PIM\Classes\Kernel\ApplicationInterface as Application;

/**
 * Die Berechtigungen, die Contentfly durchsetzt: lesen, schreiben, loeschen.
 *
 * **`canExport()` und `getExtended()` sind mit `000-000-0012` entfallen.** Beide standen im
 * `permissions`-Block des Schemas und wurden **an keiner Stelle geprueft**: Ein Benutzer mit
 * `export = 0` las, schrieb und loeschte unveraendert; ein `extended`-Eintrag schraenkte keine
 * Antwort ein.
 *
 * ENTFERNT STATT DURCHGESETZT. `canExport` haette einen Endpunkt gebraucht, den es nicht mehr
 * gibt — sein Konsument war der `ExportController`, geloescht in `012-001-0003`. `getExtended`
 * haette die Feldauswahl der API einschraenken muessen; das waere neue Funktionalitaet gewesen,
 * kein Aufraeumen.
 *
 * ENTFERNT STATT ALS METADATEN BEHALTEN. Das war die dritte Moeglichkeit, und sie ist die
 * gefaehrlichste: Ein Recht, das der Server veroeffentlicht und nicht durchsetzt, sieht wie
 * eine Zusicherung aus. Ein Client, der `export: false` liest und den Knopf ausblendet, haelt
 * sich fuer abgesichert — wer die API direkt ruft, ist davon unberuehrt. Etwas zu
 * veroeffentlichen, das nichts garantiert, ist schlechter als es wegzulassen.
 *
 * DIE SPALTEN BLEIBEN. `pim_permission.export` und `pim_permission.extended` werden nicht
 * angetastet; in einem Bestandsprojekt stehen dort moeglicherweise Werte, und Daten
 * wegzuwerfen ist die nicht umkehrbare Richtung. Lesbar sind sie weiterhin ueber
 * `Areanet\PIM\Entity\Permission` — nur eben ohne Behauptung des Frameworks darueber, was
 * sie bewirken. Sie bewirken nichts.
 *
 * DER STUFEN-KOLLAPS VON `canExport` WURDE NICHT REPARIERT, sondern mit dem Feld entfernt.
 * Er lautete `return ($permission->getExport() == 2)` — nur `ALL` galt als erlaubt, und weil
 * die Konstanten nicht aufsteigend geordnet sind (`NONE` 0, `OWN` 1, `ALL` 2, `GROUP` 3),
 * ergab ausgerechnet `GROUP` ein `false`. Jede Reparatur haette entschieden, welche Benutzer
 * kuenftig duerfen — fuer ein Recht, das niemand prueft. Wer den Export zurueckholt,
 * entscheidet das dann fuer einen Endpunkt, den es gibt.
 */
class Permission
{
    public static function isReadable(User $user, $entityName){
        return self::is('readable', $user, $entityName);
    }

    public static function isWritable(User $user, $entityName){
        return self::is('writable', $user, $entityName);
    }

    public static function isDeletable(User $user, $entityName){
        return self::is('deletable', $user, $entityName);
    }

    protected static function is($mode, User $user, $entityName, $lang = null)
    {
        $entityName = str_replace(array('Custom\\Entity\\', 'Areanet\\PIM\\Entity\\'), array('', 'PIM\\'), $entityName);

        $method= 'get'.ucfirst($mode);

        if($user->getIsAdmin()) return 2;

        if($user->getGroup() === null) return 0;

        foreach($user->getGroup()->getPermissions() as $permission){
            if($permission->getEntityName() == $entityName){
                return $permission->$method();
            }
        }

        return false;
    }
}
