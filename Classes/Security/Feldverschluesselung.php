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
 * WAS SIE SCHREIBT (010-004-0002): **XChaCha20-Poly1305** ueber libsodium. Ein
 * AEAD-Verfahren — es verschluesselt und authentifiziert in einem, und ein veraenderter
 * Chiffretext wird ABGEWIESEN statt zu Unsinn entschluesselt.
 *
 * WAS SIE LIEST: beides. Ein Wert im alten AES-256-CBC-Format bleibt lesbar, damit eine frisch
 * aktualisierte Instanz ihre Bestandsdaten weiter versteht. Umgeschluesselt wird beim naechsten
 * Schreiben — oder auf einen Schlag mit `appcms:security:reencrypt` (010-004-0003).
 *
 * WIE DAS FORMAT ERKANNT WIRD: am Chiffretext, nicht an der Konfiguration. Ein neuer Wert
 * beginnt mit `PIM1:`; alles ohne dieses Praefix ist das alte Format. Eine Konfiguration haette
 * denselben Zweck erfuellt und einen Nachteil: Sie waere umzustellen, und bis dahin laege in
 * der Datenbank beides ohne Unterscheidungsmerkmal.
 *
 * GESCHRIEBEN WIRD AUSSCHLIESSLICH NEU. Es gibt keinen Schalter, der das alte Format
 * zurueckholt. Ein solcher Schalter waere ein Weg, auf das schwaechere Verfahren
 * zurueckzudraengen, und die Migration waere nie abgeschlossen.
 *
 * WARUM CBC WEG MUSSTE, gemessen in 010-004-0001: Ein gekipptes Byte im Chiffretext geht durch
 * und liefert einen anderen Klartext — ein Block Muell, der Rest steht. Die Anwendung merkt
 * nichts und liefert es aus. `testEineManipulationFaelltAuf()` haelt fest, dass das vorbei ist.
 *
 * DER SCHLUESSEL WIRD ABGELEITET, NICHT DURCHGEREICHT. `SECURITY_CIPHER_KEY` ist eine
 * Passphrase beliebiger Laenge; libsodium verlangt genau 32 Byte. Abgeleitet wird mit
 * `crypto_generichash` (BLAKE2b) und einem festen Namensraum — deterministisch, denn derselbe
 * konfigurierte Wert muss denselben Schluessel ergeben, sonst waeren Bestandsdaten verloren.
 *
 * KEIN PASSWORT-HASH AN DIESER STELLE. Argon2id waere fuer eine vom Menschen gewaehlte
 * Passphrase das richtige Werkzeug, braucht aber ein gespeichertes Salz — und ein Salz, das
 * neben dem Chiffretext liegt, muesste bei jedem Feld mitgefuehrt werden. Das ist eine
 * Entscheidung ueber das Format und gehoert nicht in diesen Task. Revidieren, wenn
 * `SECURITY_CIPHER_KEY` als Benutzerpasswort statt als erzeugtes Geheimnis gedacht ist.
 */
final class Feldverschluesselung
{
    /**
     * Praefix eines Chiffretexts im neuen Format.
     *
     * Kurz, druckbar und unverwechselbar: Der alte Chiffretext ist base64, und `PIM1:` kann
     * dort nicht am Anfang stehen — base64 kennt keinen Doppelpunkt.
     */
    private const PRAEFIX = 'PIM1:';

    /** Namensraum der Schluesselableitung. Aenderung macht alle Bestandsdaten unlesbar. */
    private const ABLEITUNG = 'contentfly-feld';

    /**
     * Verschluesselt einen Wert mit XChaCha20-Poly1305.
     *
     * @throws \Exception wenn kein Schluessel konfiguriert ist
     */
    public function verschluesseln(string $klartext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        $chiffre = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $klartext,
            '',
            $nonce,
            $this->abgeleiteterSchluessel()
        );

        return self::PRAEFIX . base64_encode($nonce . $chiffre);
    }

    /**
     * Entschluesselt einen Wert — neues oder altes Format.
     *
     * Liefert `false`, wenn der Chiffretext nicht lesbar ist. Beim NEUEN Format heisst das:
     * Die Authentifizierung hat angeschlagen, der Wert ist veraendert worden. Beim alten:
     * Irgendetwas stimmt nicht, mehr laesst sich ohne MAC nicht sagen — genau darum geht es.
     *
     * @return string|false
     *
     * @throws \Exception wenn kein Schluessel konfiguriert ist
     */
    public function entschluesseln(string $chiffretext)
    {
        if (!$this->istNeuesFormat($chiffretext)) {
            return $this->altEntschluesseln($chiffretext);
        }

        $roh = base64_decode(substr($chiffretext, strlen(self::PRAEFIX)), true);

        if ($roh === false || strlen($roh) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            return false;
        }

        $nonce   = substr($roh, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $chiffre = substr($roh, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        return sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $chiffre,
            '',
            $nonce,
            $this->abgeleiteterSchluessel()
        );
    }

    /** Traegt der Wert das neue Format? Beantwortet den Bedarf des Re-Encrypt-Befehls. */
    public function istNeuesFormat(string $chiffretext): bool
    {
        return str_starts_with($chiffretext, self::PRAEFIX);
    }

    /**
     * Das alte AES-256-CBC-Format — nur noch lesend.
     *
     * Es wird nicht mehr geschrieben. Die Methode bleibt, solange Bestandsdaten existieren
     * koennen; sie faellt, wenn Epic 007 festgelegt hat, dass jedes migrierende Projekt
     * `appcms:security:reencrypt` durchlaufen hat.
     *
     * @return string|false
     */
    private function altEntschluesseln(string $chiffretext)
    {
        $verfahren = Adapter::getConfig()->SECURITY_CIPHER_METHOD;
        $roh       = base64_decode($chiffretext);
        $laenge    = openssl_cipher_iv_length($verfahren);

        return openssl_decrypt(
            substr($roh, $laenge),
            $verfahren,
            $this->schluessel(),
            0,
            substr($roh, 0, $laenge)
        );
    }

    /**
     * Der Schluessel fuer libsodium: genau 32 Byte, abgeleitet aus der Konfiguration.
     *
     * @throws \Exception
     */
    private function abgeleiteterSchluessel(): string
    {
        // Der Namensraum steht in der NACHRICHT, nicht im Schluesselparameter von
        // `crypto_generichash` — der verlangt mindestens 16 Byte, und eine Kennung, die man
        // nur deshalb aufblaeht, sagt weniger als eine, die man lesen kann.
        return sodium_crypto_generichash(
            self::ABLEITUNG . '|' . $this->schluessel(),
            '',
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES
        );
    }

    /**
     * Der konfigurierte Schluessel.
     *
     * Die Ausnahme ist Wort fuer Wort die aus `StringType` und `TextareaType` — sie ist das
     * einzige, was ein Aufrufer davon zu sehen bekommt, und sie bleibt unveraendert.
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
