<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

use Closure;
use Happones\Kinetix\NotificationRouting\RoutingLock;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Caps how many lookups one user has running at the same time
 * (`kinetix.search.concurrency`, off by default).
 *
 * A type-ahead aborts the request it superseded, but PHP keeps running it;
 * without a cap a fast typist stacks slow queries on the database. Each user
 * gets N lock slots (plain cache locks, so each slot is released on its own
 * and an unlocking store simply opts out). The first free slot is taken for the request and
 * released afterwards; with none free the request answers a 429 with
 * `code: busy`, which the client retries once.
 *
 * Without a cache store that can lock the middleware does nothing, like
 * {@see RoutingLock}. The default
 * `database` store can lock but is slow for this; `kinetix:doctor` says so.
 *
 * @internal
 */
final class KinetixLookupSlots
{
    public const ALIAS = 'kinetix.lookup-slots';

    public function handle(Request $request, Closure $next): Response
    {
        $slots = self::configured();
        $store = Cache::getStore();

        if ($slots === null || ! $store instanceof LockProvider) {
            return $next($request);
        }

        $lock = $this->acquire($store, $slots, self::identity($request));

        if ($lock === null) {
            return $this->busy();
        }

        try {
            return $next($request);
        } finally {
            $lock->release();
        }
    }

    /** The slots per user, or null when the cap is off. */
    public static function configured(): ?int
    {
        $value = config('kinetix.search.concurrency');

        if (! is_numeric($value) || (int) $value < 1) {
            return null;
        }

        return (int) $value;
    }

    private function acquire(LockProvider $store, int $slots, string $identity): ?Lock
    {
        $ttl = max(1, (int) config('kinetix.search.concurrency_ttl', 15));

        for ($slot = 0; $slot < $slots; $slot++) {
            $lock = $store->lock("kinetix:lookup:{$identity}:{$slot}", $ttl);

            if ($lock->get()) {
                return $lock;
            }
        }

        return null;
    }

    private static function identity(Request $request): string
    {
        $user = $request->user();

        if ($user instanceof Authenticatable) {
            return sha1($user::class.'#'.$user->getAuthIdentifier());
        }

        return 'ip:'.sha1((string) $request->ip());
    }

    private function busy(): JsonResponse
    {
        $message = __('kinetix.search_busy');

        if ($message === 'kinetix.search_busy') {
            $message = __('kinetix::kinetix.search_busy');
        }

        return new JsonResponse([
            'message'     => is_string($message) ? $message : 'The search is busy.',
            'code'        => 'busy',
            'retry_after' => 1,
        ], 429, ['Retry-After' => '1']);
    }
}
