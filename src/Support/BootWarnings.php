<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

use Illuminate\Support\Facades\Log;

/**
 * Misconfiguration warnings found while the provider boots.
 *
 * A web request under PHP-FPM boots the app every time, so logging them there
 * wrote the same line on every request of a misconfigured production app. They
 * are logged once per boot, and only where someone is looking: console
 * processes (artisan, queue workers, the scheduler, Octane workers) and the
 * `local` environment. `php artisan kinetix:doctor` reports them on demand,
 * in any environment.
 *
 * @internal
 */
final class BootWarnings
{
    /** @var array<string, true> */
    private static array $logged = [];

    public static function warn(string $key, string $message): void
    {
        if (isset(self::$logged[$key])) {
            return;
        }

        $app = app();

        if (! $app->runningInConsole() && ! $app->isLocal()) {
            return;
        }

        self::$logged[$key] = true;

        Log::warning($message);
    }

    /**
     * Forget what was logged, so the next boot logs again.
     */
    public static function flush(): void
    {
        self::$logged = [];
    }
}
