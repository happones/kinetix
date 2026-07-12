<?php

declare(strict_types=1);

namespace Happones\Kinetix\Confidential;

use RuntimeException;

/**
 * Byte-level envelope encryption for a single confidential attribute value,
 * independent of which {@see KeyManagers\KeyManager} supplied the raw Data
 * Encryption Key. Stores a self-contained JSON envelope in the host's own
 * column — no schema change beyond making sure it's a TEXT/LONGTEXT column.
 */
class ConfidentialCipher
{
    protected const CIPHER = 'aes-256-gcm';

    public function __construct(protected ConfidentialManager $manager) {}

    public function encrypt(string $plaintext): string
    {
        [$keyId, $rawKey] = $this->manager->currentKey();

        $iv         = random_bytes(12);
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $rawKey, OPENSSL_RAW_DATA, $iv, $tag);

        if ($ciphertext === false) {
            throw new RuntimeException('Kinetix Confidential: encryption failed.');
        }

        return (string) json_encode([
            'v'   => 1,
            'k'   => $keyId,
            'iv'  => base64_encode($iv),
            'tag' => base64_encode($tag),
            'c'   => base64_encode($ciphertext),
        ]);
    }

    /**
     * Decrypt a stored envelope back to its plaintext. If the stored value
     * isn't valid envelope JSON — e.g. a column that already held real
     * plaintext before `ConfidentialCast` was added — it's treated as
     * legacy plaintext and returned as-is, rather than throwing. This is
     * what makes retrofitting the cast onto an already-populated column
     * safe by default (see `kinetix:confidential:encrypt-existing`).
     */
    public function decrypt(string $stored): string
    {
        $envelope = $this->parseEnvelope($stored);

        if ($envelope === null) {
            return $stored;
        }

        $rawKey = $this->manager->dataKeyFor($envelope['k']);

        $plaintext = openssl_decrypt(
            base64_decode($envelope['c'], true) ?: '',
            self::CIPHER,
            $rawKey,
            OPENSSL_RAW_DATA,
            base64_decode($envelope['iv'], true) ?: '',
            base64_decode($envelope['tag'], true) ?: '',
        );

        if ($plaintext === false) {
            throw new RuntimeException('Kinetix Confidential: decryption failed (tampered or wrong key).');
        }

        return $plaintext;
    }

    /**
     * @return array{v: int, k: string, iv: string, tag: string, c: string}|null
     */
    protected function parseEnvelope(string $stored): ?array
    {
        $data = json_decode($stored, true);

        if (! is_array($data)
            || ! isset($data['v'], $data['k'], $data['iv'], $data['tag'], $data['c'])
            || ! is_string($data['k'])
            || ! is_string($data['iv'])
            || ! is_string($data['tag'])
            || ! is_string($data['c'])
        ) {
            return null;
        }

        /** @var array{v: int, k: string, iv: string, tag: string, c: string} $data */
        return $data;
    }
}
