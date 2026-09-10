<?php
namespace Areanet\PIM\Classes\Security;

use Areanet\PIM\Classes\Config\Adapter;
use Areanet\PIM\Entity\Group;
use Areanet\PIM\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Bildet ab, was ein Fremdsystem sagt, auf Contentfly-Gruppen (013-004-0003).
 *
 * AN EINER STELLE UND IN DER KONFIGURATION. Vorher nahm `createManagedUser($alias, $group,
 * $isAdmin)` Gruppe und Adminflag als Argumente entgegen — das heisst, jedes Projekt entschied
 * fuer sich, wie es von „das Fremdsystem sagt, der Benutzer ist in CN=Redaktion" zu einer
 * Contentfly-Gruppe kommt. Was dabei herauskam, stand in Projektcode, den niemand mehr liest.
 *
 * WAS NICHT DAZUGEHOERT: Das Contentfly-Berechtigungsmodell umzubauen. `Permission`,
 * `I18nPermission` und `Group` bleiben, wie sie sind; abgebildet wird AUF sie, nicht an ihrer
 * Stelle. Dieselbe Grenze wie in `013-002-0001`.
 *
 * SIE WIRKT BEI JEDER ANMELDUNG, nicht nur beim Anlegen. Wer im Fremdsystem aus einer Gruppe
 * faellt, faellt beim naechsten Login auch hier heraus. Genau deshalb stehen Rollen und Gruppen
 * NICHT im JWT (`013-003-0001`) — dort waeren sie bis zum Ablauf eingefroren.
 *
 * IM ZWEIFEL KEINE RECHTE. Ohne passenden Eintrag gibt es keine Gruppe und kein Adminflag. Eine
 * Abbildung, die im Zweifel Rechte vergibt, ist die falsche Richtung: Das Fremdsystem soll
 * Rechte begruenden, nicht ihr Fehlen.
 */
final class Gruppenabbildung
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Setzt Gruppe und Adminflag des Benutzers aus dem, was der Provider geliefert hat.
     */
    public function anwenden(string $anbieter, Fremdkennung $fremd, User $benutzer): void
    {
        $regeln = $this->regeln($anbieter);

        if ($regeln === null) {
            return;
        }

        /*
         * DAS ADMINFLAG WIRD IMMER GESETZT, auch auf false.
         *
         * Wer im Fremdsystem aus der Admingruppe faellt, soll sie hier verlieren — und zwar
         * beim naechsten Login. Nur zu setzen, wenn ein Treffer vorliegt, hiesse: Einmal
         * Administrator, immer Administrator.
         */
        $benutzer->setIsAdmin($this->istAdmin($regeln, $fremd));

        $gruppenname = $this->gruppenname($regeln, $fremd);

        if ($gruppenname === null) {
            /*
             * Keine Zuordnung und keine Vorgabe: Die Gruppe wird ABGERAEUMT, nicht
             * stehengelassen. Sonst behielte jemand die Rechte einer Gruppe, aus der ihn das
             * Fremdsystem entfernt hat.
             */
            $benutzer->setGroup(null);
            $this->em->flush();

            return;
        }

        $gruppe = $this->em->getRepository(Group::class)->findOneBy(array('name' => $gruppenname));

        if (!$gruppe instanceof Group) {
            /*
             * EINE FEHLKONFIGURATION SCHLAEGT LAUT DURCH.
             *
             * Sie stillschweigend zu ignorieren hiesse: Der Benutzer kommt herein und hat
             * andere Rechte als gedacht — und niemand erfaehrt, warum. Die Anmeldung
             * abzubrechen ist die sichere Richtung, und die Meldung nennt die Gruppe.
             */
            throw new \RuntimeException(sprintf(
                'SECURITY_PROVIDER_GRUPPEN bildet den Anbieter "%s" auf die Gruppe "%s" ab, die '
                .'es in pim_group nicht gibt.',
                $anbieter,
                $gruppenname
            ));
        }

        $benutzer->setGroup($gruppe);
        $this->em->flush();
    }

    /**
     * @return array{gruppen: array<string,string>, admin: list<string>, vorgabe: string|null}|null
     */
    private function regeln(string $anbieter): ?array
    {
        $alle = Adapter::getConfig()->SECURITY_PROVIDER_GRUPPEN;

        if (!is_array($alle) || !isset($alle[$anbieter]) || !is_array($alle[$anbieter])) {
            return null;
        }

        $eintrag = $alle[$anbieter];

        return array(
            'gruppen' => is_array($eintrag['gruppen'] ?? null) ? $eintrag['gruppen'] : array(),
            'admin'   => is_array($eintrag['admin'] ?? null) ? array_values($eintrag['admin']) : array(),
            'vorgabe' => is_string($eintrag['vorgabe'] ?? null) ? $eintrag['vorgabe'] : null,
        );
    }

    /**
     * @param array{gruppen: array<string,string>, admin: list<string>, vorgabe: string|null} $regeln
     */
    private function istAdmin(array $regeln, Fremdkennung $fremd): bool
    {
        foreach ($regeln['admin'] as $fremdgruppe) {
            if (in_array($fremdgruppe, $fremd->gruppen, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Der erste Treffer in der konfigurierten Reihenfolge — oder die Vorgabe.
     *
     * DIE REIHENFOLGE IST EINE ENTSCHEIDUNG. Ein Benutzer kann in mehreren Fremdgruppen sein;
     * Contentfly kennt genau eine Gruppe je Benutzer. Welche gewinnt, steht dann in der
     * Konfiguration und nicht in der Laune einer Hashtabelle.
     *
     * @param array{gruppen: array<string,string>, admin: list<string>, vorgabe: string|null} $regeln
     */
    private function gruppenname(array $regeln, Fremdkennung $fremd): ?string
    {
        foreach ($regeln['gruppen'] as $fremdgruppe => $contentflyGruppe) {
            if (in_array((string) $fremdgruppe, $fremd->gruppen, true)) {
                return (string) $contentflyGruppe;
            }
        }

        return $regeln['vorgabe'];
    }
}
