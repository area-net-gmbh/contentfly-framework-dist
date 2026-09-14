<?php
namespace Areanet\PIM\Classes\Security;

use Areanet\PIM\Classes\Config\Adapter;

/**
 * Encrypts and decrypts the values of fields marked `#[PIM\Config(encoded: true)]`.
 *
 * WHY THIS CLASS EXISTS (010-004-0001). The same code used to live twice in the tree, in
 * `StringType` and `TextareaType`, identical line for line. A change to the algorithm would have had
 * to be made and checked twice — and exactly such a change was due with `010-004-0002`.
 *
 * WHAT IT WRITES (010-004-0002): **XChaCha20-Poly1305** via libsodium. An AEAD algorithm — it
 * encrypts and authenticates in one step, and a modified ciphertext is REJECTED instead of being
 * decrypted into garbage.
 *
 * WHAT IT READS: both. A value in the old AES-256-CBC format stays readable so that a freshly updated
 * instance keeps understanding its existing data. It is re-encrypted on the next write — or all at
 * once with `appcms:security:reencrypt` (010-004-0003).
 *
 * HOW THE FORMAT IS RECOGNISED: by the ciphertext, not by configuration. A new value starts with
 * `PIM1:`; everything without that prefix is the old format. A configuration would have served the
 * same purpose with one drawback: it would have to be switched, and until then the database would
 * hold both without a distinguishing mark.
 *
 * ONLY THE NEW FORMAT IS WRITTEN. There is no switch that brings back the old format. Such a switch
 * would be a way to push back to the weaker algorithm, and the migration would never be complete.
 *
 * WHY CBC HAD TO GO, measured in 010-004-0001: a flipped byte in the ciphertext goes through and yields
 * a different plaintext — one block of garbage, the rest intact. The application notices nothing and
 * delivers it. `testEineManipulationFaelltAuf()` records that this is over.
 *
 * THE KEY IS DERIVED, NOT PASSED THROUGH. `SECURITY_CIPHER_KEY` is a passphrase of any length;
 * libsodium requires exactly 32 bytes. It is derived with `crypto_generichash` (BLAKE2b) and a fixed
 * context — deterministically, because the same configured value must yield the same key, otherwise
 * existing data would be lost.
 *
 * NO PASSWORD HASH AT THIS POINT. Argon2id would be the right tool for a passphrase chosen by a human,
 * but it needs a stored salt — and a salt stored next to the ciphertext would have to be carried along
 * with every field. That is a decision about the format and does not belong in this task. Revisit if
 * `SECURITY_CIPHER_KEY` is meant as a user password rather than a generated secret.
 */
final class FieldEncryption
{
    /**
     * Prefix of a ciphertext in the new format.
     *
     * Short, printable and unmistakable: the old ciphertext is base64, and `PIM1:` cannot appear at its
     * start — base64 has no colon.
     */
    private const PREFIX = 'PIM1:';

    /**
     * Context of the key derivation. Changing it makes all existing data unreadable.
     *
     * It was `contentfly-feld` until 014-002-0003 and was changed while no production data had been
     * encrypted with it yet — the new format has not been in a release. From the first release on this
     * value is fixed: every value encrypted after that depends on these exact bytes.
     */
    private const KEY_DERIVATION_CONTEXT = 'contentfly-field';

    /**
     * Encrypts a value with XChaCha20-Poly1305.
     *
     * @throws \Exception if no key is configured
     */
    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            '',
            $nonce,
            $this->derivedKey()
        );

        return self::PREFIX . base64_encode($nonce . $cipher);
    }

    /**
     * Decrypts a value — new or old format.
     *
     * Returns `false` if the ciphertext cannot be read. For the NEW format that means: authentication
     * failed, the value has been modified. For the old one: something is wrong, and without a MAC
     * nothing more can be said — that is exactly the point.
     *
     * @return string|false
     *
     * @throws \Exception if no key is configured
     */
    public function decrypt(string $ciphertext)
    {
        if (!$this->isNewFormat($ciphertext)) {
            return $this->decryptLegacy($ciphertext);
        }

        $raw = base64_decode(substr($ciphertext, strlen(self::PREFIX)), true);

        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            return false;
        }

        $nonce  = substr($raw, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        return sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $cipher,
            '',
            $nonce,
            $this->derivedKey()
        );
    }

    /** Does the value carry the new format? Serves the needs of the re-encrypt command. */
    public function isNewFormat(string $ciphertext): bool
    {
        return str_starts_with($ciphertext, self::PREFIX);
    }

    /**
     * The old AES-256-CBC format — read-only.
     *
     * It is no longer written. The method stays as long as existing data may exist; it goes once epic
     * 007 has settled that every migrating project has run `appcms:security:reencrypt`.
     *
     * @return string|false
     */
    private function decryptLegacy(string $ciphertext)
    {
        $method = Adapter::getConfig()->SECURITY_CIPHER_METHOD;
        $raw    = base64_decode($ciphertext);
        $length = openssl_cipher_iv_length($method);

        return openssl_decrypt(
            substr($raw, $length),
            $method,
            $this->key(),
            0,
            substr($raw, 0, $length)
        );
    }

    /**
     * The key for libsodium: exactly 32 bytes, derived from the configuration.
     *
     * @throws \Exception
     */
    private function derivedKey(): string
    {
        // The context goes into the MESSAGE, not into the key parameter of `crypto_generichash` —
        // that one requires at least 16 bytes, and an identifier inflated only for that reason says
        // less than one you can read.
        return sodium_crypto_generichash(
            self::KEY_DERIVATION_CONTEXT . '|' . $this->key(),
            '',
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES
        );
    }

    /**
     * The configured key.
     *
     * The exception is what a caller gets to see of all this, so its message names the setting that
     * is missing.
     *
     * @throws \Exception
     */
    private function key(): string
    {
        $key = Adapter::getConfig()->SECURITY_CIPHER_KEY;

        if (empty($key)) {
            throw new \Exception('A value for SECURITY_CIPHER_KEY must be set for encryption.');
        }

        return $key;
    }
}
