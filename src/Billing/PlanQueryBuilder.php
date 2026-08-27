<?php

declare(strict_types=1);

namespace Happones\Kinetix\Billing;

use Illuminate\Database\Eloquent\Builder;

/**
 * The query builder behind {@see Plan}, kept in sync with {@see PlanCatalog}.
 *
 * `Plan::booted()` flushes the catalog on model events, but a BULK write —
 * `Plan::query()->update([...])`, the natural way to re-price a tier or flip a
 * feature across plans — fires no model events at all. Without this the
 * request that performed the update would keep answering plan questions from
 * the pre-update catalog (and, with `billing.cache.ttl` set, so would every
 * request until the entry expired).
 *
 * Raw `DB::table('plans')` writes still bypass Eloquent entirely; that is the
 * documented reason to leave `billing.cache.ttl` null when plans are written
 * by something outside the model.
 *
 * @extends Builder<Plan>
 */
class PlanQueryBuilder extends Builder
{
    /**
     * @param array<string, mixed> $values
     */
    public function update(array $values): int
    {
        return tap(parent::update($values), static function (): void {
            PlanCatalog::flush();
        });
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @param  array<int, string>|string $uniqueBy
     * @param  array<int, string>|null   $update
     * @return int
     */
    public function upsert(array $values, $uniqueBy, $update = null)
    {
        return tap(parent::upsert($values, $uniqueBy, $update), static function (): void {
            PlanCatalog::flush();
        });
    }

    /**
     * @return mixed
     */
    public function delete()
    {
        return tap(parent::delete(), static function (): void {
            PlanCatalog::flush();
        });
    }

    /**
     * @param array<int|string, mixed> $values
     */
    public function insert(array $values): bool
    {
        return tap($this->toBase()->insert($values), static function (): void {
            PlanCatalog::flush();
        });
    }
}
