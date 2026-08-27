<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

use Closure;
use WeakMap;

/**
 * Per-object, per-request memoization for verdicts that are asked repeatedly
 * within a single request but cost a query (or a relation load) to resolve.
 *
 * Authorization is the canonical case: `Gate::before` fires on EVERY check,
 * the record-action pass runs once per table row, and the entitlement layers
 * are asked again for every button on the page — all with the same answer.
 *
 * Entries are keyed by the **subject object** in a `WeakMap`, so they are
 * released as soon as the subject is garbage-collected: distinct users never
 * collide and nothing leaks between requests under Octane or in a queue
 * worker (unlike a static array keyed by id, which would). A `$store`
 * namespaces unrelated memos, and an inner `$key` distinguishes contexts of
 * the same subject (the permissions team id, the subscription type, …).
 *
 *     Memo::remember('billing.plan', $team, 'default', fn () => $this->resolveCurrentPlan());
 *
 * Null is a cacheable value: a resolver returning null is not re-run.
 */
final class Memo
{
    /**
     * One WeakMap per store; each maps a subject to its bucket of keyed values.
     *
     * @var array<string, WeakMap<object, array<string, mixed>>>
     */
    private static array $stores = [];

    /**
     * The memoized value for ($store, $subject, $key), resolving it on first
     * ask. `$resolve` runs at most once per subject per key per request.
     */
    public static function remember(string $store, object $subject, string $key, Closure $resolve): mixed
    {
        $map = self::$stores[$store] ??= new WeakMap;

        /** @var array<string, mixed> $bucket */
        $bucket = $map[$subject] ?? [];

        if (! array_key_exists($key, $bucket)) {
            $bucket[$key]  = $resolve();
            $map[$subject] = $bucket;
        }

        return $bucket[$key];
    }

    /**
     * Drop memoized values. With no arguments every store is cleared; with a
     * `$store` only that one; with a `$subject` only that subject's bucket
     * (and with a `$key`, only that entry).
     *
     * Long-running processes that MUTATE what was memoized — a worker that
     * changes a subscription, a test that swaps a role — must call this, the
     * same contract as `SuperAdmin::flush()`.
     */
    public static function flush(?string $store = null, ?object $subject = null, ?string $key = null): void
    {
        if ($store === null) {
            self::$stores = [];

            return;
        }

        if ($subject === null) {
            unset(self::$stores[$store]);

            return;
        }

        $map = self::$stores[$store] ?? null;

        if ($map === null || ! isset($map[$subject])) {
            return;
        }

        if ($key === null) {
            unset($map[$subject]);

            return;
        }

        /** @var array<string, mixed> $bucket */
        $bucket = $map[$subject];
        unset($bucket[$key]);
        $map[$subject] = $bucket;
    }
}
