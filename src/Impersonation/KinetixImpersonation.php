<?php

declare(strict_types=1);

namespace Happones\Kinetix\Impersonation;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Static entry point for impersonation:
 *
 *     KinetixImpersonation::start($user);
 *     KinetixImpersonation::isImpersonating();
 *     KinetixImpersonation::stop();
 */
class KinetixImpersonation
{
    public static function manager(): ImpersonationManager
    {
        return app(ImpersonationManager::class);
    }

    public static function isImpersonating(): bool
    {
        return static::manager()->isImpersonating();
    }

    public static function impersonatorId(): int|string|null
    {
        return static::manager()->impersonatorId();
    }

    public static function start(Authenticatable $target): void
    {
        static::manager()->start($target);
    }

    public static function stop(): void
    {
        static::manager()->stop();
    }
}
