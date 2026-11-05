<?php

declare(strict_types=1);

namespace Happones\Kinetix\Confidential;

use Closure;
use Happones\Kinetix\Activity\KinetixActivity;
use Happones\Kinetix\Confidential\KeyManagers\KeyManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Resolves whether confidential attributes should be revealed right now, and
 * keeps the unwrapped Data Encryption Key(s) in process memory so a
 * KMS-backed {@see KeyManager} is called at most once per
 * `key_cache_ttl_minutes` window and process, not once per confidential value
 * rendered. The shared cache only ever holds key rows (id + wrapped key).
 */
class ConfidentialManager
{
    /**
     * Process-local override stack for {@see revealed()} — works in any
     * context (HTTP, queued job, CLI), independent of the session.
     *
     * @var array<int, bool>
     */
    protected static array $overrideStack = [];

    /**
     * Prefix of the (shared) cache entries holding key ROWS — never raw keys.
     */
    protected const CACHE_PREFIX = 'kinetix-confidential:v2:';

    /**
     * The unwrapped Data Encryption Keys, by key id, each until it expires.
     * Process memory only: a raw key is never written to the cache.
     *
     * @var array<string, array{raw: string, expiresAt: int}>
     */
    protected array $unwrapped = [];

    public function __construct(protected KeyManager $keyManager) {}

    /**
     * Whether confidential attributes should currently render their real
     * value. Outside an active, cookie-backed HTTP session (e.g. inside a
     * queued job) `session()` resolves a fresh, empty, per-process store
     * that was never populated by `unlock()` — so this naturally returns
     * `false` there without any special-casing.
     */
    public function isUnlocked(): bool
    {
        if (self::$overrideStack !== [] && end(self::$overrideStack)) {
            return true;
        }

        $unlockedAt = session('kinetix_confidential_unlocked_at');

        if ($unlockedAt === null) {
            return false;
        }

        $ttl = (int) config('kinetix.confidential.reveal_ttl_minutes', 5);

        return now()->lessThan(Carbon::parse($unlockedAt)->addMinutes($ttl));
    }

    /**
     * When the current reveal window expires, for the frontend's countdown —
     * `null` if not currently unlocked. Server-side truth is always
     * re-checked via {@see isUnlocked()}; this is cosmetic only.
     */
    public function unlockedUntil(): ?Carbon
    {
        $unlockedAt = session('kinetix_confidential_unlocked_at');

        if ($unlockedAt === null) {
            return null;
        }

        $ttl       = (int) config('kinetix.confidential.reveal_ttl_minutes', 5);
        $expiresAt = Carbon::parse($unlockedAt)->addMinutes($ttl);

        return $expiresAt->isFuture() ? $expiresAt : null;
    }

    /**
     * Confirm the current user's password and open the reveal window for
     * `reveal_ttl_minutes`. Returns false on a denied gate or wrong password.
     */
    public function unlock(string $password): bool
    {
        if (! Gate::allows('revealKinetixConfidential')) {
            return false;
        }

        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        if (config('kinetix.confidential.require_password', true) && ! $this->passwordMatches($user, $password)) {
            return false;
        }

        session()->put('kinetix_confidential_unlocked_at', now());

        $this->log('confidential.unlocked', $user);

        return true;
    }

    /**
     * Close the reveal window immediately, before its TTL naturally expires.
     */
    public function lock(): void
    {
        session()->forget('kinetix_confidential_unlocked_at');

        $user = auth()->user();

        if ($user !== null) {
            $this->log('confidential.locked', $user);
        }
    }

    /**
     * Temporarily treat the current process as unlocked for the duration of
     * the callback — a process-local escape hatch (mirrors
     * `Model::withoutEvents()`) for an explicitly-authorized, synchronous
     * backend code path that needs real values outside the session/UI flow
     * (e.g. a bespoke admin export action with its own re-auth + audit log).
     * Queued jobs stay masked unless they explicitly wrap their confidential
     * reads in this.
     */
    public function revealed(Closure $callback): mixed
    {
        self::$overrideStack[] = true;

        try {
            return $callback();
        } finally {
            array_pop(self::$overrideStack);
        }
    }

    /**
     * Mask a plaintext value, keeping `$visible` real trailing (or leading)
     * characters. Does not preserve separators/format (e.g. dashes in an
     * SSN) — a v1 simplification.
     */
    public function mask(string $plaintext, ?int $visible = null, ?string $position = null): string
    {
        $length  = strlen($plaintext);
        $visible = max(0, min($visible ?? (int) config('kinetix.confidential.mask_visible', 4), $length));
        $hidden  = str_repeat('•', $length - $visible);

        if ($position === 'head') {
            return ($visible > 0 ? substr($plaintext, 0, $visible) : '').$hidden;
        }

        return $hidden.($visible > 0 ? substr($plaintext, -$visible) : '');
    }

    /**
     * The current (not-retired) Data Encryption Key, unwrapped.
     *
     * @return array{0: string, 1: string} [keyId, rawKey]
     */
    public function currentKey(): array
    {
        $row = $this->keyRow(
            self::CACHE_PREFIX.'current',
            static fn (): ?ConfidentialKey => ConfidentialKey::query()->where('is_current', true)->first(),
        );

        if ($row === null) {
            throw new RuntimeException(
                'Kinetix Confidential: no current encryption key. Run `php artisan kinetix:confidential:rotate-key`.',
            );
        }

        return [$row['key_id'], $this->unwrappedKey($row['key_id'], $row['wrapped_key'])];
    }

    /**
     * Resolve the raw key for a specific (possibly retired) key_id, so
     * historical envelopes stay decryptable after rotation.
     */
    public function dataKeyFor(string $keyId): string
    {
        $row = $this->keyRow(
            self::CACHE_PREFIX."key:{$keyId}",
            static fn (): ?ConfidentialKey => ConfidentialKey::query()->where('key_id', $keyId)->first(),
        );

        if ($row === null) {
            throw new RuntimeException("Kinetix Confidential: unknown key_id [{$keyId}] — cannot decrypt.");
        }

        return $this->unwrappedKey($row['key_id'], $row['wrapped_key']);
    }

    /**
     * Forget the unwrapped keys this process holds, so the next read unwraps
     * again (after a rotation, or to drop key material from a long-lived
     * worker on demand).
     */
    public function flushKeys(): void
    {
        $this->unwrapped = [];
    }

    /**
     * The cache keys (the shared cache, not this process) a key row may live
     * under, legacy names included, for `kinetix:confidential:rotate-key` to
     * purge.
     *
     * @param  array<int, string> $keyIds
     * @return array<int, string>
     */
    public static function cacheKeys(array $keyIds): array
    {
        $keys = [self::CACHE_PREFIX.'current', 'kinetix-confidential-current-key'];

        foreach ($keyIds as $keyId) {
            $keys[] = self::CACHE_PREFIX."key:{$keyId}";
            $keys[] = "kinetix-confidential-key:{$keyId}";
            $keys[] = "kinetix-confidential-dek:{$keyId}";
        }

        return $keys;
    }

    /**
     * A key row as cached: only its id and its WRAPPED key, as scalars. The
     * raw key never goes to the cache, which is shared and often a database
     * table; nor does a serialized model.
     *
     * @param  Closure(): ?ConfidentialKey                     $query
     * @return array{key_id: string, wrapped_key: string}|null
     */
    protected function keyRow(string $cacheKey, Closure $query): ?array
    {
        $row = Cache::remember($cacheKey, now()->addMinutes($this->keyCacheTtl()), static function () use ($query): ?array {
            $key = $query();

            return $key === null ? null : ['key_id' => (string) $key->key_id, 'wrapped_key' => (string) $key->wrapped_key];
        });

        return is_array($row) ? $row : null;
    }

    /**
     * The raw key, unwrapped at most once per `key_cache_ttl_minutes` and
     * held in this process only.
     */
    protected function unwrappedKey(string $keyId, string $wrappedKey): string
    {
        $now    = now()->getTimestamp();
        $cached = $this->unwrapped[$keyId] ?? null;

        if ($cached !== null && $cached['expiresAt'] > $now) {
            return $cached['raw'];
        }

        foreach ($this->unwrapped as $id => $entry) {
            if ($entry['expiresAt'] <= $now) {
                unset($this->unwrapped[$id]);
            }
        }

        $raw = $this->keyManager->unwrap($wrappedKey);

        $this->unwrapped[$keyId] = ['raw' => $raw, 'expiresAt' => now()->addMinutes($this->keyCacheTtl())->getTimestamp()];

        return $raw;
    }

    protected function keyCacheTtl(): int
    {
        return (int) config('kinetix.confidential.key_cache_ttl_minutes', 10);
    }

    protected function passwordMatches(Authenticatable $user, string $password): bool
    {
        $hash = (string) $user->getAuthPassword();

        return $hash !== '' && Hash::check($password, $hash);
    }

    protected function log(string $event, Authenticatable $causer): void
    {
        if (! config('kinetix.activity.enabled', false)) {
            return;
        }

        KinetixActivity::log($event, null, [], $causer instanceof Model ? $causer : null);
    }
}
