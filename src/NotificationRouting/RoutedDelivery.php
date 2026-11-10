<?php

declare(strict_types=1);

namespace Happones\Kinetix\NotificationRouting;

use Closure;

/**
 * The team a routed delivery runs for, so the database channel can stamp it
 * on the row. Set for the duration of one delivery and cleared after, even
 * when it throws: nothing outlives the job in a long-lived worker.
 *
 * @internal
 */
final class RoutedDelivery
{
    private static int|string|null $team = null;

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn $callback
     * @return TReturn
     */
    public static function within(int|string|null $team, Closure $callback): mixed
    {
        $previous   = self::$team;
        self::$team = $team;

        try {
            return $callback();
        } finally {
            self::$team = $previous;
        }
    }

    public static function team(): int|string|null
    {
        return self::$team;
    }
}
