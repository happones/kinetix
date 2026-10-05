<?php

declare(strict_types=1);

namespace Happones\Kinetix\Dismissals;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * The account-wide memory of closed alerts — what lets a `permanent` close
 * follow the user to every device. The frontend writes it through
 * `<KinetixAlert dismiss-mode="permanent">`; the server reads it to skip
 * rendering an alert at all:
 *
 *     if (! KinetixDismissals::has($user, 'complete-profile')) { … }
 *
 * Keys are the alerts' `dismiss-key`s: opaque strings the app chooses.
 */
class KinetixDismissals
{
    /** Keys look like `complete-profile`, `beta:2026` or `flash:uuid`. */
    public const KEY_PATTERN = '/^[A-Za-z0-9._:-]{1,191}$/';

    /**
     * How many keys ride the page payload. A user closes a handful of alerts;
     * the cap only guards the payload against an app that mints keys per page.
     */
    public const SHARED_LIMIT = 500;

    /**
     * Close `$key` for the user, until `$until` when given (it comes back on
     * its own afterwards). Closing again moves the dates forward.
     */
    public static function dismiss(Model $user, string $key, ?CarbonInterface $until = null): Dismissal
    {
        return Dismissal::query()->updateOrCreate(
            ['user_id' => $user->getKey(), 'key' => $key],
            ['dismissed_at' => now(), 'expires_at' => $until],
        );
    }

    /** Show `$key` to the user again. */
    public static function restore(Model $user, string $key): void
    {
        Dismissal::query()
            ->where('user_id', $user->getKey())
            ->where('key', $key)
            ->delete();
    }

    /** Whether the user closed `$key` and the close is still in force. */
    public static function has(Model $user, string $key): bool
    {
        return Dismissal::query()
            ->active()
            ->where('user_id', $user->getKey())
            ->where('key', $key)
            ->exists();
    }

    /**
     * Every key the user has closed, newest first — what the page payload
     * carries so a closed alert never renders, on any device.
     *
     * @return list<string>
     */
    public static function keysFor(Model $user): array
    {
        /** @var list<string> $keys */
        $keys = Dismissal::query()
            ->active()
            ->where('user_id', $user->getKey())
            ->latest('dismissed_at')
            ->limit(self::SHARED_LIMIT)
            ->pluck('key')
            ->all();

        return $keys;
    }
}
