<?php
namespace Areanet\PIM\Classes\Security;

/**
 * Was ein Anmeldeprovider vom Fremdsystem zurueckbringt (013-004-0001).
 *
 * DAS IST DIE GANZE SCHNITTSTELLE ZUM PROJEKT. Ein Provider prueft gegen sein Fremdsystem und
 * sagt danach: „Das ist der Benutzer, und das steht dort ueber ihn." Alles Weitere — Benutzer
 * finden oder anlegen, Gruppen setzen, Token ausstellen — macht das Framework.
 *
 * VORHER GAB ES DIESE TRENNUNG NICHT: `LoginManager::auth()` musste eine fertige
 * `User`-Entity liefern und dafuer `createManagedUser()` rufen. Damit lag die Provisionierung im
 * Projekt — jedes fuer sich, mit jeweils eigenen Fehlern. Ein Provider hat jetzt mit der
 * Datenbank nichts mehr zu tun.
 *
 * SIE IST UNVERAENDERLICH. Ein Wert, den die Anmeldung unterwegs noch aendern koennte, waere ein
 * Einfallstor: Ein Hook, der `kennung` umschreibt, meldete jemand anderen an.
 */
final class Fremdkennung
{
    /**
     * @param string             $kennung   Die Kennung des Benutzers IM FREMDSYSTEM — nicht der
     *                                      Contentfly-Alias. Sie wird lesbar gespeichert.
     * @param list<string>       $gruppen   Was das Fremdsystem an Gruppen liefert, unveraendert.
     *                                      Die Abbildung auf Contentfly-Gruppen macht das
     *                                      Framework (013-004-0003).
     * @param array<string,mixed> $attribute Alles Weitere, was das Fremdsystem mitgibt.
     */
    public function __construct(
        public readonly string $kennung,
        public readonly array $gruppen = array(),
        public readonly array $attribute = array(),
    ) {
        if (trim($kennung) === '') {
            throw new \InvalidArgumentException(
                'Eine Fremdkennung ohne Kennung gibt es nicht. Ein Provider, der niemanden '
                .'erkannt hat, liefert null statt einer leeren Kennung.'
            );
        }
    }
}
