<?php

declare(strict_types=1);

namespace Happones\Kinetix\Confidential;

use Closure;

/**
 * Static facade over {@see ConfidentialManager}, mirroring the
 * `KinetixActivity`/`KinetixReportsCenter` static-facade style used
 * elsewhere in the package.
 */
class Confidential
{
    public static function isUnlocked(): bool
    {
        return app(ConfidentialManager::class)->isUnlocked();
    }

    public static function unlock(string $password): bool
    {
        return app(ConfidentialManager::class)->unlock($password);
    }

    public static function lock(): void
    {
        app(ConfidentialManager::class)->lock();
    }

    public static function revealed(Closure $callback): mixed
    {
        return app(ConfidentialManager::class)->revealed($callback);
    }

    public static function mask(string $plaintext, ?int $visible = null, ?string $position = null): string
    {
        return app(ConfidentialManager::class)->mask($plaintext, $visible, $position);
    }
}
