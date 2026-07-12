<?php

declare(strict_types=1);

namespace Happones\Kinetix\Confidential\KeyManagers;

use Happones\Kinetix\Confidential\ConfidentialCipher;

/**
 * Resolves the raw 32-byte Data Encryption Key (DEK) used by
 * {@see ConfidentialCipher} to encrypt/decrypt
 * confidential attribute values, without exposing how that key is actually
 * protected. Kinetix ships {@see LocalKeyManager} (wraps via the app's own
 * `APP_KEY`, zero network calls). A host app can bind its own implementation
 * — e.g. backed by AWS KMS, GCP KMS, or HashiCorp Vault Transit — via
 * `config('kinetix.confidential.key_manager')` pointing at the class name;
 * see docs/confidential.md for a worked (non-shipped) example.
 */
interface KeyManager
{
    /**
     * Generate a brand-new random Data Encryption Key, already wrapped for
     * storage. Called once per key rotation, never per row/value.
     *
     * @return array{plaintext: string, wrapped: string}
     */
    public function generateDataKey(): array;

    /**
     * Unwrap a previously-wrapped key back to its raw 32 bytes.
     */
    public function unwrap(string $wrapped): string;
}
