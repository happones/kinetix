<?php

declare(strict_types=1);

namespace Happones\Kinetix\Confidential\KeyManagers;

use Illuminate\Support\Facades\Crypt;

/**
 * Zero-dependency default: wraps/unwraps the Data Encryption Key with the
 * host app's own `APP_KEY` via Laravel's `Crypt` facade. No network calls,
 * no extra config — the right default for self-hosted deployments; teams
 * needing a real KMS bind their own {@see KeyManager} instead.
 */
class LocalKeyManager implements KeyManager
{
    public function generateDataKey(): array
    {
        $plaintext = random_bytes(32);

        return [
            'plaintext' => $plaintext,
            'wrapped'   => $this->wrap($plaintext),
        ];
    }

    public function unwrap(string $wrapped): string
    {
        return (string) base64_decode(Crypt::decryptString($wrapped), true);
    }

    protected function wrap(string $rawKey): string
    {
        return Crypt::encryptString(base64_encode($rawKey));
    }
}
