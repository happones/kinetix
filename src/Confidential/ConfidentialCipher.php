<?php

declare(strict_types=1);

namespace Happones\Kinetix\Confidential;

use RuntimeException;

/**
 * Byte-level envelope encryption for a single confidential attribute value,
 * independent of which {@see KeyManagers\KeyManager} supplied the raw Data
 * Encryption Key. Stores a self-contained JSON envelope in the host's own
 * column — no schema change beyond making sure it's a TEXT/LONGTEXT column.
 *
 * Envelope versions:
 *
 *  - v2 (written whenever a {@see ConfidentialContext} is given, which the
 *    cast always does): AES-256-GCM sealed with associated data naming the
 *    table, the column and the row (`r`, the row key it is bound to; null
 *    while the row has no key yet). A ciphertext moved to another row or
 *    column, or whose `r` is edited, no longer decrypts.
 *  - v1 (no context: host code calling the cipher directly, and every value
 *    written before v2): no associated data. Read while
 *    `kinetix.confidential.legacy_envelopes` is true (the default);
 *    `kinetix:confidential:encrypt-existing` re-wraps them as v2.
 *
 * Both require a 12-byte IV and a 16-byte tag: a shortened tag is refused.
 */
class ConfidentialCipher
{
    protected const CIPHER = 'aes-256-gcm';

    protected const IV_LENGTH = 12;

    protected const TAG_LENGTH = 16;

    public function __construct(protected ConfidentialManager $manager) {}

    /**
     * Encrypt `$plaintext`: a v2 envelope sealed to `$context` (bound to its
     * row key, or to the column alone while the row has none), or a v1
     * envelope without a context.
     */
    public function encrypt(string $plaintext, ?ConfidentialContext $context = null): string
    {
        [$keyId, $rawKey] = $this->manager->currentKey();

        $iv  = random_bytes(self::IV_LENGTH);
        $tag = '';
        $aad = $context?->aad($context->rowKey) ?? '';

        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $rawKey, OPENSSL_RAW_DATA, $iv, $tag, $aad, self::TAG_LENGTH);

        if ($ciphertext === false) {
            throw new RuntimeException('Kinetix Confidential: encryption failed.');
        }

        $envelope = [
            'v'   => $context === null ? 1 : 2,
            'k'   => $keyId,
            'iv'  => base64_encode($iv),
            'tag' => base64_encode($tag),
            'c'   => base64_encode($ciphertext),
        ];

        if ($context !== null) {
            $envelope['r'] = $context->rowKey;
        }

        return (string) json_encode($envelope);
    }

    /**
     * Decrypt a stored envelope back to its plaintext. If the stored value
     * isn't valid envelope JSON — e.g. a column that already held real
     * plaintext before `ConfidentialCast` was added — it's treated as
     * legacy plaintext and returned as-is, rather than throwing. This is
     * what makes retrofitting the cast onto an already-populated column
     * safe by default (see `kinetix:confidential:encrypt-existing`).
     *
     * A v2 envelope needs the context it was sealed to, and its bound row
     * key must be the context's: anything else throws.
     */
    public function decrypt(string $stored, ?ConfidentialContext $context = null): string
    {
        $envelope = $this->parseEnvelope($stored);

        if ($envelope === null) {
            return $stored;
        }

        $iv  = base64_decode($envelope['iv'], true);
        $tag = base64_decode($envelope['tag'], true);

        if ($iv === false || strlen($iv) !== self::IV_LENGTH || $tag === false || strlen($tag) !== self::TAG_LENGTH) {
            throw new RuntimeException('Kinetix Confidential: malformed envelope (IV or tag length).');
        }

        if ($envelope['v'] === 1) {
            $aad = $this->legacyAad();
        } elseif ($envelope['v'] === 2) {
            $aad = $this->boundAad($envelope, $context);
        } else {
            throw new RuntimeException('Kinetix Confidential: unknown envelope version.');
        }

        $rawKey = $this->manager->dataKeyFor($envelope['k']);

        $plaintext = openssl_decrypt(
            base64_decode($envelope['c'], true) ?: '',
            self::CIPHER,
            $rawKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad,
        );

        if ($plaintext === false) {
            throw new RuntimeException('Kinetix Confidential: decryption failed (tampered, moved or wrong key).');
        }

        return $plaintext;
    }

    /**
     * The version and bound row key of a stored envelope; null when the
     * value isn't one (legacy plaintext).
     *
     * @internal
     *
     * @return array{v: int, r: int|string|null}|null
     */
    public function describe(string $stored): ?array
    {
        $envelope = $this->parseEnvelope($stored);

        return $envelope === null ? null : ['v' => $envelope['v'], 'r' => $envelope['r'] ?? null];
    }

    /** A v1 envelope has no associated data, and reads only while legacy envelopes are allowed. */
    protected function legacyAad(): string
    {
        if (! config('kinetix.confidential.legacy_envelopes', true)) {
            throw new RuntimeException('Kinetix Confidential: legacy (v1) envelopes are disabled; re-wrap them with kinetix:confidential:encrypt-existing.');
        }

        return '';
    }

    /**
     * The associated data of a v2 envelope, checked against where it is
     * read: a context is required, and a row-bound envelope read on a row
     * must be read on THAT row. A read that doesn't know its row (a
     * `pluck()`, `value()` or a select without the key hydrates a model
     * with no key) is checked against the table and column only. A
     * column-bound envelope (`r: null`) reads on any row of its column,
     * except in strict mode (`legacy_envelopes` false) on a read that knows
     * its row.
     *
     * @param array{v: int, k: string, iv: string, tag: string, c: string, r?: int|string|null} $envelope
     */
    protected function boundAad(array $envelope, ?ConfidentialContext $context): string
    {
        if ($context === null) {
            throw new RuntimeException('Kinetix Confidential: a v2 envelope needs the context it was sealed to.');
        }

        $bound = $envelope['r'] ?? null;

        if ($bound !== null && $context->rowKey !== null && (string) $bound !== (string) $context->rowKey) {
            throw new RuntimeException('Kinetix Confidential: the value is bound to another row.');
        }

        // Strict mode: on a read that knows its row, a value bound to the
        // column only (a row written without model events) is refused, as it
        // could have been copied from any row — unless it is the very
        // envelope this process sealed for this instance, read by a
        // `created` listener before the binding listener ran.
        if ($bound === null && $context->rowKey !== null && ! $context->pending && ! config('kinetix.confidential.legacy_envelopes', true)) {
            throw new RuntimeException('Kinetix Confidential: the value is not bound to its row; re-wrap it with kinetix:confidential:encrypt-existing.');
        }

        return $context->aad($bound);
    }

    /**
     * @return array{v: int, k: string, iv: string, tag: string, c: string, r?: int|string|null}|null
     */
    protected function parseEnvelope(string $stored): ?array
    {
        $data = json_decode($stored, true);

        if (! is_array($data)
            || ! isset($data['v'], $data['k'], $data['iv'], $data['tag'], $data['c'])
            || ! is_int($data['v'])
            || ! is_string($data['k'])
            || ! is_string($data['iv'])
            || ! is_string($data['tag'])
            || ! is_string($data['c'])
        ) {
            return null;
        }

        if (array_key_exists('r', $data) && $data['r'] !== null && ! is_int($data['r']) && ! is_string($data['r'])) {
            throw new RuntimeException('Kinetix Confidential: malformed envelope (row key).');
        }

        /** @var array{v: int, k: string, iv: string, tag: string, c: string, r?: int|string|null} $data */
        return $data;
    }
}
