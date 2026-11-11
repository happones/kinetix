<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Fakes;

use Illuminate\Database\Eloquent\Model;

/**
 * Static host callbacks for the routing tests (callable arrays, as in config).
 */
class RoutingCallbacks
{
    /** @var list<array{0: int|string|null, 1: string}> */
    public static array $availabilityCalls = [];

    public static bool $available = true;

    public static int $labelCalls = 0;

    /** @var array<int|string, bool> */
    public static array $availableByTeam = [];

    /** @var list<Model> */
    public static array $fallbackUsers = [];

    /** @var list<array{0: int|string|null, 1: ?object}> */
    public static array $fallbackCalls = [];

    public static function reset(): void
    {
        self::$availabilityCalls = [];
        self::$available         = true;
        self::$labelCalls        = 0;
        self::$availableByTeam   = [];
        self::$fallbackUsers     = [];
        self::$fallbackCalls     = [];
    }

    public static function label(string $name, int|string|null $teamId): ?string
    {
        return $name === 'admin' ? 'Company admin' : null;
    }

    public static function countingLabel(string $name, int|string|null $teamId): ?string
    {
        self::$labelCalls++;

        return null;
    }

    public static function nothing(int|string|null $teamKey, ?object $notification): mixed
    {
        return null;
    }

    public static function available(int|string|null $teamKey, string $type): bool
    {
        self::$availabilityCalls[] = [$teamKey, $type];

        return $teamKey !== null && array_key_exists($teamKey, self::$availableByTeam) ? self::$availableByTeam[$teamKey] : self::$available;
    }

    /**
     * @return list<Model>
     */
    public static function fallback(int|string|null $teamKey, ?object $notification): array
    {
        self::$fallbackCalls[] = [$teamKey, $notification];

        return self::$fallbackUsers;
    }
}
