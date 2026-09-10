<?php
namespace Areanet\PIM\Classes\Security;

use Areanet\PIM\Classes\Config\Adapter;

/**
 * Ver- und entschluesselt die Werte von Feldern mit `#[PIM\Config(encoded: true)]`.
 *
 * WOZU ES DIESE KLASSE GIBT (010-004-0001). Derselbe Code stand bis hierher zweimal im Baum,
 * in `StringType` und `TextareaType`, Zeile fuer Zeile gleich. Eine Aenderung am Verfahren
 * waere zweimal zu machen und zweimal zu pruefen gewesen — und genau eine solche Aenderung
 * steht mit `010-004-0002` an.
 *
 * DIESE KLASSE AENDERT DAS VERFAHREN NICHT. Sie ist der vorhandene AES-256-CBC-Code, an einer
 * Stelle. Was sie erzeugt, liest der alte Code, und was der alte Code erzeugt hat, liest sie —
 * beides ist in `tests/Unit/Security/FeldverschluesselungTest.php` festgehalten.
 *
 * WAS AN DIESEM VERFAHREN NICHT STIMMT, und warum `010-004-0002` folgt:
 *
 * **CBC ist unauthentifiziert.** Es gibt keinen MAC. Wer den Chiffretext veraendern kann,
 * veraendert den Klartext gezielt mit — und die Anwendung merkt nichts, sie entschluesselt das
 * Ergebnis und liefert es aus. Ein AEAD-Verfahren erkennt die Manipulation und verweigert.
 * `testEinManipulierterChiffretextFaelltHeuteNichtAuf()` haelt diesen Zustand fest; er ist der
 * Grund der ganzen Story.
 *
 * **Der Schluessel wird roh durchgereicht.** `SECURITY_CIPHER_KEY` geht unveraendert an
 * `openssl_encrypt()`. Eine Passphrase beliebiger Laenge ist kein Schluessel; OpenSSL fuellt
 * oder kuerzt stillschweigend. Auch das loest `010-004-0002`.
 */
final class Feldverschluesselung
{
    /**
     * Verschluesselt einen Wert.
     *
     * @throws \Exception wenn kein Schluessel konfiguriert ist
     */
    public function verschluesseln(string $klartext): string
    {
        $schluessel = $this->schluessel();
        $verfahren  = Adapter::getConfig()->SECURITY_CIPHER_METHOD;

        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($verfahren));

        // `openssl_encrypt()` liefert mit $options = 0 bereits base64. Der IV wird davor
        // gehaengt und das Ganze noch einmal kodiert — verschwenderisch, aber es ist das
        // Format, in dem Bestandsdaten liegen. Es bleibt deshalb, wie es ist.
        return base64_encode($iv . openssl_encrypt($klartext, $verfahren, $schluessel, 0, $iv));
    }

    /**
     * Entschluesselt einen Wert.
     *
     * Liefert `false`, wenn der Chiffretext nicht lesbar ist — das ist das Verhalten von
     * `openssl_decrypt()` und wird hier NICHT geglaettet: Der alte Code gab es so weiter, und
     * dieser Task aendert kein Verhalten.
     *
     * @return string|false
     *
     * @throws \Exception wenn kein Schluessel konfiguriert ist
     */
    public function entschluesseln(string $chiffretext)
    {
        $schluessel = $this->schluessel();
        $verfahren  = Adapter::getConfig()->SECURITY_CIPHER_METHOD;

        $roh    = base64_decode($chiffretext);
        $laenge = openssl_cipher_iv_length($verfahren);

        return openssl_decrypt(substr($roh, $laenge), $verfahren, $schluessel, 0, substr($roh, 0, $laenge));
    }

    /**
     * Der konfigurierte Schluessel.
     *
     * Die Ausnahme ist Wort fuer Wort die aus `StringType` und `TextareaType` — sie ist das
     * einzige, was ein Aufrufer heute davon zu sehen bekommt, und sie bleibt unveraendert.
     *
     * @throws \Exception
     */
    private function schluessel(): string
    {
        $schluessel = Adapter::getConfig()->SECURITY_CIPHER_KEY;

        if (empty($schluessel)) {
            throw new \Exception('Für die Verschlüsselung muss ein Wert für SECURITY_CIPHER_KEY gesetzt sein.');
        }

        return $schluessel;
    }
}
