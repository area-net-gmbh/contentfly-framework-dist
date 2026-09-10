<?php
namespace Areanet\PIM\Classes\Security;

use Symfony\Component\HttpFoundation\Request;

/**
 * Der Vertrag fuer eine Anmeldung ueber ein Fremdsystem (013-004-0001).
 *
 * EINE PFLICHT, UND ES IST DIE EINZIGE, DIE EIN PROJEKT HAT: gegen das Fremdsystem pruefen.
 * Alles andere steuert das Framework bei — den Benutzer finden oder anlegen, Gruppen und
 * Adminflag setzen, den Token ausstellen, abmelden.
 *
 * DAS IST DIE GRUNDIDEE DES ALTEN `LoginManager`, und sie bleibt. Was faellt, sind seine
 * Konstruktionsfehler: Er verlangte eine fertige `User`-Entity und schob damit die
 * Provisionierung ins Projekt, wo sie jedes Mal neu erfunden wurde — mit `setPass($alias)` als
 * prominentestem Ergebnis.
 *
 * DER PROVIDER FASST DIE DATENBANK NICHT AN. Er bekommt den Request und gibt eine
 * `Fremdkennung` zurueck — oder `null`, wenn er niemanden erkannt hat. Wer hier eine Ausnahme
 * wirft, bekommt dasselbe Ergebnis: Der Aufrufer erfaehrt nur, dass es nicht gereicht hat.
 */
interface Anmeldeprovider
{
    /**
     * Prueft die Anmeldedaten des Requests gegen das Fremdsystem.
     *
     * @return Fremdkennung|null null heisst abgelehnt — ohne Angabe eines Grundes nach aussen
     */
    public function pruefen(Request $request): ?Fremdkennung;
}
