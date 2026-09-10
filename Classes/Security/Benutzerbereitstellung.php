<?php
namespace Areanet\PIM\Classes\Security;

use Areanet\PIM\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Legt einen Benutzer an, den ein Fremdsystem erkannt hat — oder findet ihn wieder
 * (013-004-0002).
 *
 * SIE GEHOERT INS FRAMEWORK UND NICHT INS PROJEKT. Vorher tat das `createManagedUser()` auf der
 * abstrakten `LoginManager`-Klasse, gerufen aus dem Projekt-Code — und jedes Projekt konnte es
 * anders machen oder vergessen. Ein Provider hat mit der Datenbank seit `013-004-0001` nichts
 * mehr zu tun; er sagt, wer da ist, und das hier macht daraus einen Benutzer.
 *
 * DREI DINGE, DIE VORHER FALSCH WAREN:
 *
 *   setPass($alias)   Das Passwort war der Benutzername (Befund A-6). Jetzt ist es gesperrt.
 *   md5-Praefix       `md5($klasse).'-'.$alias` machte Benutzer unwiedererkennbar. Jetzt
 *                     stehen Herkunft und Kennung in eigenen Feldern, und der Alias liest sich.
 *   Aufloesung        Gefunden wurde ueber den verfremdeten Alias. Jetzt ueber Provider UND
 *                     Kennung — dieselbe Eindeutigkeit, nur lesbar.
 */
final class Benutzerbereitstellung
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Der Benutzer zu dieser Fremdkennung — vorhanden oder neu angelegt.
     *
     * @param string $anbieter Der NAME des Providers aus dem `Anbieterverzeichnis`
     */
    public function findenOderAnlegen(string $anbieter, Fremdkennung $fremd): User
    {
        $vorhanden = $this->em->getRepository(User::class)->findOneBy(array(
            'loginManager' => $anbieter,
            'externalId'   => $fremd->kennung,
        ));

        if ($vorhanden instanceof User) {
            return $vorhanden;
        }

        $benutzer = new User();
        $benutzer->setAlias(self::alias($anbieter, $fremd->kennung));
        $benutzer->setExternalId($fremd->kennung);
        $benutzer->setLoginManager($anbieter);

        /*
         * GESPERRT, BEVOR IRGENDETWAS ANDERES PASSIERT.
         *
         * Nicht erst am Ende der Methode: Ein `return` oder eine Ausnahme dazwischen — heute
         * gibt es keine, morgen vielleicht — hinterliesse sonst eine Zeile mit dem
         * Vorgabewert der Spalte und einem Konto, das jemand uebernehmen kann.
         */
        $benutzer->passwortSperren();

        $benutzer->setIsAdmin(false);
        $benutzer->setIsActive(true);

        $this->em->persist($benutzer);
        $this->em->flush();

        return $benutzer;
    }

    /**
     * Der Contentfly-Alias fuer eine Fremdkennung.
     *
     * `<provider>:<kennung>` statt `md5(<klasse>)-<kennung>`. Er muss eindeutig sein — die
     * Spalte ist es —, und er soll lesbar sein: Wer in `pim_user` nachsieht, will erkennen, wer
     * das ist und woher er kommt. Beides leistet der Doppelpunkt, den MD5 nicht leistete.
     */
    public static function alias(string $anbieter, string $kennung): string
    {
        return $anbieter.':'.$kennung;
    }
}
