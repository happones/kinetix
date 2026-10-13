<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables;

use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;
use JsonSerializable;
use UnitEnum;
use WeakMap;

/**
 * The row a table shows for a record when it is presented through a Laravel
 * API resource ({@see Table::apiResource()}): what the resource returns,
 * resolved the way a JSON response resolves it — nested resources and
 * collections through their own `toArray()`, conditional values
 * (`whenLoaded()`, `when()`) dropped when they don't apply. Dates and enums
 * stay objects, so date and badge columns can still format them.
 *
 * Columns read their state from it while the row is built; the record itself
 * stays the model, for keys, authorization and actions. A key the resource
 * doesn't return is empty — never read off the model behind it, which would
 * show what the resource chose to leave out.
 *
 * @internal
 */
final class ApiResourceRow
{
    /** @var WeakMap<Model, array<array-key, mixed>>|null */
    private static ?WeakMap $rows = null;

    /**
     * Resolve the resource and keep its row for the record until
     * {@see forget()}.
     */
    public static function present(Model $record, JsonResource $resource, Request $request): void
    {
        self::$rows ??= new WeakMap;

        $row = self::normalize($resource->resolve($request), $request);

        self::$rows[$record] = is_array($row) ? $row : [];
    }

    /**
     * The presented row of a record, or null when it isn't presented.
     *
     * @return array<array-key, mixed>|null
     */
    public static function of(Model $record): ?array
    {
        return self::$rows[$record] ?? null;
    }

    public static function forget(Model $record): void
    {
        if (self::$rows !== null) {
            unset(self::$rows[$record]);
        }
    }

    /**
     * A resolved value as its JSON response would carry it, short of turning
     * dates and enums into strings.
     */
    private static function normalize(mixed $value, Request $request): mixed
    {
        return match (true) {
            $value instanceof JsonResource                                  => self::normalize($value->resolve($request), $request),
            $value instanceof DateTimeInterface, $value instanceof UnitEnum => $value,
            $value instanceof Arrayable                                     => self::normalize($value->toArray(), $request),
            $value instanceof JsonSerializable                              => self::normalize($value->jsonSerialize(), $request),
            is_array($value)                                                => self::normalizeArray($value, $request),
            default                                                         => $value,
        };
    }

    /**
     * @param  array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    private static function normalizeArray(array $values, Request $request): array
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            if ($value instanceof MissingValue) {
                continue;
            }

            $normalized[$key] = self::normalize($value, $request);
        }

        return $normalized;
    }
}
