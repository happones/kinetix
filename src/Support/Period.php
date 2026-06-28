<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Resolves a dashboard period key (the common "Last 7 days / Last 30 days /
 * This month / …" filter) into a concrete `[start, end]` date range, and scopes
 * Eloquent queries by it. Pairs with the `<KinetixPeriodFilter>` Vue component
 * and the `useKinetixPeriod` composable.
 *
 *     [$start, $end] = Period::range('7d');
 *     Order::query()->tap(fn ($q) => Period::scope($q, 'created_at', request('period')))->get();
 */
class Period
{
    /**
     * The supported period keys.
     */
    public const KEYS = ['today', 'yesterday', '7d', '30d', '90d', 'month', 'year', 'all'];

    /**
     * Resolve a period key to a `[start, end]` range. `all` (and unknown keys)
     * returns `[null, null]` (no bounds). For `custom`, pass `$from`/`$to`.
     *
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    public static function range(string $key, ?string $from = null, ?string $to = null): array
    {
        $now = CarbonImmutable::now();

        return match ($key) {
            'today'     => [$now->startOfDay(), $now->endOfDay()],
            'yesterday' => [$now->subDay()->startOfDay(), $now->subDay()->endOfDay()],
            '7d'        => [$now->subDays(6)->startOfDay(), $now->endOfDay()],
            '30d'       => [$now->subDays(29)->startOfDay(), $now->endOfDay()],
            '90d'       => [$now->subDays(89)->startOfDay(), $now->endOfDay()],
            'month'     => [$now->startOfMonth(), $now->endOfMonth()],
            'year'      => [$now->startOfYear(), $now->endOfYear()],
            'custom'    => [
                $from !== null ? CarbonImmutable::parse($from)->startOfDay() : null,
                $to   !== null ? CarbonImmutable::parse($to)->endOfDay() : null,
            ],
            default => [null, null],
        };
    }

    /**
     * Read the period from a request: `?period=7d` (+ `?from=&to=` for custom).
     *
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    public static function fromRequest(Request $request, string $default = '30d'): array
    {
        $key = (string) $request->input('period', $default);

        return static::range($key, $request->input('from'), $request->input('to'));
    }

    /**
     * Constrain a query to a period on the given column. A `null`-bounded range
     * (e.g. `all`) leaves the query untouched.
     *
     * @param Builder<Model> $query
     */
    public static function scope(Builder $query, string $column, string $key, ?string $from = null, ?string $to = null): Builder
    {
        [$start, $end] = static::range($key, $from, $to);

        if ($start !== null) {
            $query->where($column, '>=', $start);
        }

        if ($end !== null) {
            $query->where($column, '<=', $end);
        }

        return $query;
    }
}
