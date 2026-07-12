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
 * caches the unwrapped Data Encryption Key(s) so a KMS-backed
 * {@see KeyManager} is called at most once per cache window, not once per
 * confidential value rendered.
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
     * The current (not-retired) Data Encryption Key, unwrapped and cached.
     *
     * @return array{0: string, 1: string} [keyId, rawKey]
     */
    public function currentKey(): array
    {
        $row = Cache::remember(
            'kinetix-confidential-current-key',
            now()->addMinutes($this->keyCacheTtl()),
            fn () => ConfidentialKey::query()->where('is_current', true)->first(),
        );

        if ($row === null) {
            throw new RuntimeException(
                'Kinetix Confidential: no current encryption key. Run `php artisan kinetix:confidential:rotate-key`.',
            );
        }

        return [$row->key_id, $this->unwrappedKey($row->key_id, $row->wrapped_key)];
    }

    /**
     * Resolve the raw key for a specific (possibly retired) key_id, so
     * historical envelopes stay decryptable after rotation.
     */
    public function dataKeyFor(string $keyId): string
    {
        $row = Cache::remember(
            "kinetix-confidential-key:{$keyId}",
            now()->addMinutes($this->keyCacheTtl()),
            fn () => ConfidentialKey::query()->where('key_id', $keyId)->first(),
        );

        if ($row === null) {
            throw new RuntimeException("Kinetix Confidential: unknown key_id [{$keyId}] — cannot decrypt.");
        }

        return $this->unwrappedKey($row->key_id, $row->wrapped_key);
    }

    protected function unwrappedKey(string $keyId, string $wrappedKey): string
    {
        return Cache::remember(
            "kinetix-confidential-dek:{$keyId}",
            now()->addMinutes($this->keyCacheTtl()),
            fn () => $this->keyManager->unwrap($wrappedKey),
        );
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
