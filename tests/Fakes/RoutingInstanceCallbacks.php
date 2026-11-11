<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Fakes;

/**
 * A host callback class with an instance method: counts how often it is built.
 */
class RoutingInstanceCallbacks
{
    public static int $instances = 0;

    public function __construct()
    {
        self::$instances++;
    }

    public function available(int|string|null $teamKey, string $type): bool
    {
        return true;
    }
}
