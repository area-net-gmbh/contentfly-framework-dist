<?php
namespace Areanet\PIM\Classes\Security;

/**
 * Kann dieser Provider sagen, ob es eine Kennung noch gibt? (013-005-0002)
 *
 * EINE ZUSATZFAEHIGKEIT, KEINE PFLICHT. `Anmeldeprovider` verlangt genau eine Methode, und das
 * bleibt so: Ein Provider, der nur eine vorgezeigte Anmeldung pruefen kann, ist ein
 * vollstaendiger Provider. Wer darueber hinaus das Fremdsystem befragen kann, ohne dass jemand
 * sein Passwort vorzeigt, implementiert zusaetzlich dieses Interface.
 *
 * WOFUER: Wer aus dem Verzeichnis verschwindet, kommt nicht mehr herein — das ergibt sich von
 * selbst. Sein Contentfly-Konto bleibt aber, und mit ihm ein Refresh-Token, das bis zu seinem
 * Zeitlimit weiter frische Access-JWT holt. Ein Benutzer, den die Personalabteilung entfernt
 * hat, arbeitet also weiter, bis ein Zeitlimit ablaeuft, das niemand dafuer gewaehlt hat.
 * `appcms:provider:abgleich` schliesst das — und braucht dafuer genau diese Frage.
 *
 * NICHT JEDER PROVIDER KANN SIE BEANTWORTEN. Ein OIDC-Provider etwa prueft einen Token, den der
 * Client mitbringt; ohne Token hat er keine Handhabe, nach einer Kennung zu fragen. Er
 * implementiert dieses Interface deshalb nicht, und der Abgleich ueberspringt ihn — sichtbar,
 * nicht stillschweigend.
 */
interface Bestandspruefung
{
    /**
     * Kennt das Fremdsystem diese Kennung noch?
     *
     * DREI ANTWORTEN, UND DIE DRITTE IST DIE WICHTIGSTE:
     *
     *   true   vorhanden
     *   false  nicht mehr vorhanden
     *   null   **kann es gerade nicht sagen** — Verzeichnis nicht erreichbar, Dienstkonto
     *          abgelehnt, Zeitüberschreitung
     *
     * Ein Ausfall darf nicht wie ein geloeschter Benutzer aussehen. Waere die Antwort ein
     * `bool`, muesste ein nicht erreichbares Verzeichnis entweder als `true` (dann wirkt der
     * Abgleich nie) oder als `false` (dann sperrt ein Netzwerkfehler die ganze Belegschaft aus)
     * gelesen werden. Beides ist falsch, also gibt es die dritte Antwort.
     */
    public function kenntKennung(string $kennung): ?bool;
}
