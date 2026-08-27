<?php

declare(strict_types=1);

namespace Happones\Kinetix\Billing;

use Happones\Kinetix\Support\Memo;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The `plans` table as an in-memory catalog.
 *
 * Plans are a small, near-static catalog (a handful of rows that change when
 * pricing changes), but every plan question used to hit the database:
 * `canUseFeature()`, `planAllows()`, `planLimit()` each re-ran
 * {@see Concerns\HasPlan::currentPlan()}, and each of those ran a `plans`
 * query. A page that gates ten things — ten plan-gated feature flags resolved
 * for the `kinetix_features` share, a toolbar of plan-locked buttons — paid
 * ten queries for one answer, per request, per tenant.
 *
 * Loading the catalog once and resolving in memory removes those queries
 * entirely: after the first ask, price-id / slug / free-plan lookups are array
 * scans over a few rows.
 *
 * Two layers, in order:
 *
 *  1. **Per-request memo** (always on) — one query per request, at most.
 *  2. **Persistent cache** (opt-in via `kinetix.billing.cache.ttl`) — zero
 *     queries across requests. Writes through the model flush it
 *     automatically ({@see Plan::booted()}), so an edit applies on the next
 *     request; a `ttl` of null (the default) keeps the safe behavior of
 *     re-reading the table once per request.
 *
 * Ordering matches what the replaced queries did: the free-plan fallback keeps
 * `active()->ordered()` semantics, and lookups scan in primary-key order so a
 * catalog with duplicate price ids resolves deterministically.
 */
final class PlanCatalog
{
    /**
     * The {@see Memo} store holding each billable's RESOLVED plan. It is
     * derived from this catalog, so flushing the catalog must flush it too —
     * otherwise a billable resolved before a plan edit would keep answering
     * from the pre-edit plan for the rest of the request.
     */
    public const RESOLVED_MEMO = 'billing.plan';

    /** @var Collection<int, Plan>|null */
    private static ?Collection $memo = null;

    /**
     * Every plan, active or not, in primary-key order.
     *
     * @return Collection<int, Plan>
     */
    public static function all(): Collection
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $ttl = self::ttl();

        if ($ttl === null) {
            return self::$memo = self::query();
        }

        $store = self::store();

        if ($store === null) {
            return self::$memo = self::query();
        }

        try {
            /** @var Collection<int, Plan> $plans */
            $plans = $store->remember(self::cacheKey(), $ttl, static fn (): Collection => self::query());
        } catch (Throwable) {
            // A cache store that can't serialize the models (or is simply
            // down) must never take billing with it — fall back to the query.
            $plans = self::query();
        }

        return self::$memo = $plans;
    }

    /**
     * The plan sold at a Stripe price id (monthly or yearly).
     *
     * A blank id never matches: plans whose price columns are `''` — the usual
     * seed/import artifact — would otherwise all match it, silently granting
     * the wrong plan's features.
     */
    public static function byPriceId(?string $priceId): ?Plan
    {
        if (! filled($priceId)) {
            return null;
        }

        return self::all()->first(static function (Plan $plan) use ($priceId): bool {
            return in_array($priceId, [$plan->stripePriceId('monthly'), $plan->stripePriceId('yearly')], true);
        });
    }

    public static function bySlug(?string $slug): ?Plan
    {
        if (! filled($slug)) {
            return null;
        }

        return self::all()->first(static fn (Plan $plan): bool => $plan->slug === $slug);
    }

    /**
     * The plan a billable falls back to with no paid subscription: the first
     * ACTIVE free plan in display order (`sort_order`, then price).
     */
    public static function free(): ?Plan
    {
        return self::all()
            ->filter(static fn (Plan $plan): bool => (bool) $plan->is_active && $plan->isFree())
            ->sortBy(static fn (Plan $plan): array => [$plan->sort_order, (float) $plan->monthly_price])
            ->first();
    }

    /**
     * Drop the catalog — the request memo AND the persistent entry. Called
     * automatically whenever a plan is saved or deleted through the model.
     */
    public static function flush(): void
    {
        self::flushMemo();

        if (self::ttl() === null) {
            return;
        }

        try {
            self::store()?->forget(self::cacheKey());
        } catch (Throwable) {
            // Nothing to do: the request memo is already cleared, and a store
            // that can't be reached can't be holding a stale entry we serve.
        }
    }

    /**
     * Drop ONLY the per-request memo, leaving any persistent entry in place.
     *
     * The memo is a plain static, so in a long-running process (Octane, a
     * queue worker) it would otherwise outlive the request that filled it and
     * serve a catalog edited elsewhere. Kinetix calls this at the start of
     * every request and every queued job; `flush()` — which also drops the
     * persistent entry — is for actual plan WRITES.
     */
    public static function flushMemo(): void
    {
        self::$memo = null;

        Memo::flush(self::RESOLVED_MEMO);
    }

    /**
     * @return Collection<int, Plan>
     */
    private static function query(): Collection
    {
        /** @var class-string<Plan> $model */
        $model = config('kinetix.billing.plan_model', Plan::class);

        /** @var Collection<int, Plan> $plans */
        $plans = $model::query()->orderBy((new $model)->getKeyName())->get();

        return $plans;
    }

    private static function ttl(): ?int
    {
        $ttl = config('kinetix.billing.cache.ttl');

        return $ttl === null ? null : max(0, (int) $ttl);
    }

    private static function store(): ?Repository
    {
        try {
            return Cache::store(config('kinetix.billing.cache.store'));
        } catch (Throwable) {
            return null;
        }
    }

    private static function cacheKey(): string
    {
        return 'kinetix.billing.plans';
    }
}
