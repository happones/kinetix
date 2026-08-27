<?php

declare(strict_types=1);

namespace Happones\Kinetix\Credentials;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The password lifecycle: how long a password stays valid, which ones may not
 * be reused, and when a user is forced to change theirs before doing anything
 * else.
 *
 * Two columns on the host's `users` table carry the state — `password_changed_at`
 * and `must_change_password` — and {@see PasswordObserver} keeps them (and the
 * history) up to date **whatever** changes the password: Fortify, a reset link,
 * a seeder, tinker. Nothing here has to be called by hand for the policy to
 * hold; the public methods exist for the cases where you want to ask, or to
 * issue a credential yourself.
 *
 * Every knob is off by default (`expires_after_days` null, `history` 0), so
 * enabling the module changes nothing until you opt into a rule.
 */
class PasswordPolicy
{
    /**
     * Comparing a candidate against a stored hash is deliberately slow (that is
     * what a password hash is for), so the history is capped: at bcrypt's
     * default cost each comparison runs ~50-100ms, and a change would otherwise
     * take seconds. Five is already beyond what most policies ask for.
     */
    public const MAX_HISTORY = 5;

    /**
     * Whether the module is on at all. With it off every method here is inert,
     * so a host can leave the calls in place while the feature is disabled.
     */
    public function enabled(): bool
    {
        return (bool) config('kinetix.credentials.enabled', false);
    }

    // -----------------------------------------------------------------
    // Expiry
    // -----------------------------------------------------------------

    /**
     * Days a password stays valid, or null when passwords never expire.
     */
    public function expiryDays(): ?int
    {
        $days = config('kinetix.credentials.passwords.expires_after_days');

        return $days === null ? null : max(1, (int) $days);
    }

    /**
     * When this user's password stops being valid, or null when it never does
     * (no policy, or no `password_changed_at` to count from).
     */
    public function expiresAt(mixed $user): ?Carbon
    {
        $days = $this->expiryDays();

        if (! $this->enabled() || $days === null || ! $user instanceof Model) {
            return null;
        }

        $changedAt = $user->getAttribute('password_changed_at');

        // A user whose password predates the policy has no stamp. Treating that
        // as "expired" would lock out every existing account the moment the
        // policy is switched on, so it counts as current until they next change
        // it — run a backfill if you want the policy to apply retroactively.
        if (! $changedAt instanceof Carbon) {
            return null;
        }

        return $changedAt->copy()->addDays($days);
    }

    public function isExpired(mixed $user): bool
    {
        $expiresAt = $this->expiresAt($user);

        return $expiresAt !== null && $expiresAt->isPast();
    }

    /**
     * Whole days until the password expires — negative once it has. Null when
     * it never expires, so `null` and `0` are never confused.
     */
    public function daysUntilExpiry(mixed $user): ?int
    {
        $expiresAt = $this->expiresAt($user);

        return $expiresAt === null ? null : (int) ceil(now()->diffInDays($expiresAt, false));
    }

    /**
     * Whether the user is inside the window where the UI should warn them the
     * password is about to expire (`warn_before_days`, 0 disables the warning).
     */
    public function isExpiring(mixed $user): bool
    {
        $days = $this->daysUntilExpiry($user);
        $warn = (int) config('kinetix.credentials.passwords.warn_before_days', 0);

        return $days !== null && $warn > 0 && $days >= 0 && $days <= $warn;
    }

    // -----------------------------------------------------------------
    // Forced change
    // -----------------------------------------------------------------

    public function mustChange(mixed $user): bool
    {
        return $this->enabled()
            && $user instanceof Model
            && (bool) $user->getAttribute('must_change_password');
    }

    /**
     * Whether this user has to change their password before being allowed to
     * use the app — either flagged (a temporary credential) or expired.
     */
    public function requiresChange(mixed $user): bool
    {
        return $this->mustChange($user) || $this->isExpired($user);
    }

    /**
     * Flag a user to change their password at their next request. What an admin
     * issuing a temporary credential sets; also useful after a breach.
     */
    public function forceChange(Model $user): void
    {
        $user->forceFill(['must_change_password' => true])->save();
    }

    /**
     * Issue a temporary password: a generated credential the user must replace
     * on first use. Returns the plaintext **once** — it is hashed on the way in
     * and cannot be read back, exactly like a Sanctum token.
     *
     * Pair it with {@see temporaryExpiresAt()} at your login: a temporary
     * credential that was never used should stop working, and Kinetix does not
     * own your login flow.
     */
    public function issueTemporary(Model $user, ?string $plain = null): string
    {
        $plain ??= $this->generate();

        $user->forceFill([
            'password'             => Hash::make($plain),
            'must_change_password' => true,
        ])->save();

        return $plain;
    }

    /**
     * When an unused temporary credential stops being valid, or null when the
     * user has no pending forced change (or no TTL is configured).
     */
    public function temporaryExpiresAt(mixed $user): ?Carbon
    {
        $hours = config('kinetix.credentials.passwords.temporary_ttl_hours');

        if ($hours === null || ! $this->mustChange($user) || ! $user instanceof Model) {
            return null;
        }

        $changedAt = $user->getAttribute('password_changed_at');

        return $changedAt instanceof Carbon
            ? $changedAt->copy()->addHours(max(1, (int) $hours))
            : null;
    }

    public function temporaryHasExpired(mixed $user): bool
    {
        $expiresAt = $this->temporaryExpiresAt($user);

        return $expiresAt !== null && $expiresAt->isPast();
    }

    /**
     * A random credential that satisfies the usual "upper, lower, digit"
     * complexity checks without being unreadable over the phone.
     */
    public function generate(int $length = 12): string
    {
        $length = max(8, $length);

        return Str::upper(Str::random(1))
            .Str::lower(Str::random(1))
            .(string) random_int(0, 9)
            .Str::random($length - 3);
    }

    // -----------------------------------------------------------------
    // History
    // -----------------------------------------------------------------

    /**
     * How many previous passwords are remembered (0 = the rule is off).
     */
    public function historyDepth(): int
    {
        return min(self::MAX_HISTORY, max(0, (int) config('kinetix.credentials.passwords.history', 0)));
    }

    /**
     * Whether a candidate matches one of the user's recent passwords.
     *
     * The CURRENT password is included: "you can't reuse the last 3" has to
     * mean the one in use too, and the current hash may not be in the history
     * table yet (it is written there as the change happens).
     */
    public function wasUsedBefore(mixed $user, string $candidate): bool
    {
        $depth = $this->historyDepth();

        if (! $this->enabled() || $depth === 0 || ! $user instanceof Model || $candidate === '') {
            return false;
        }

        $current = $user->getAttribute('password');

        if (is_string($current) && $current !== '' && Hash::check($candidate, $current)) {
            return true;
        }

        $hashes = PasswordHistory::query()
            ->where('user_id', $user->getKey())
            ->orderByDesc('id')
            ->limit($depth)
            ->pluck('password');

        foreach ($hashes as $hash) {
            if (is_string($hash) && $hash !== '' && Hash::check($candidate, $hash)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record a hash as used and stamp the change, then prune anything past the
     * remembered depth so the table can't grow without bound.
     *
     * Called automatically by {@see PasswordObserver}; call it directly only
     * when writing a password somewhere the observer can't see (a raw query).
     */
    public function record(Model $user, ?string $hash = null): void
    {
        $hash ??= (string) $user->getAttribute('password');

        if ($hash === '') {
            return;
        }

        $depth = $this->historyDepth();

        if ($depth > 0 && ! $this->alreadyNewest($user, $hash)) {
            PasswordHistory::query()->create([
                'user_id'    => $user->getKey(),
                'password'   => $hash,
                'created_at' => now(),
            ]);

            $this->prune($user, $depth);
        }
    }

    /**
     * Whether this exact hash is already the newest entry.
     *
     * Recording the same hash twice would silently SHORTEN the history — two of
     * the remembered N slots spent on one password — so a re-save, or an
     * observer the host registered a second time on top of Kinetix's, is a
     * no-op rather than a subtle weakening of the rule.
     */
    protected function alreadyNewest(Model $user, string $hash): bool
    {
        return PasswordHistory::query()
            ->where('user_id', $user->getKey())
            ->orderByDesc('id')
            ->value('password') === $hash;
    }

    /**
     * Drop everything older than the newest `$depth` entries for a user.
     */
    protected function prune(Model $user, int $depth): void
    {
        // Ordered by the primary key, not `created_at`: several changes inside
        // one second share a timestamp, and "most recent" has to be exact or
        // pruning drops arbitrary rows.
        $keep = PasswordHistory::query()
            ->where('user_id', $user->getKey())
            ->orderByDesc('id')
            ->limit($depth)
            ->pluck('id');

        PasswordHistory::query()
            ->where('user_id', $user->getKey())
            ->whereNotIn('id', $keep)
            ->delete();
    }

    /**
     * Forget a user's password history — for a GDPR erasure, or when an admin
     * deliberately resets someone's policy state.
     */
    public function forget(Authenticatable|Model $user): void
    {
        PasswordHistory::query()->where('user_id', $user->getAuthIdentifier())->delete();
    }

    /**
     * The policy as the frontend sees it (the `kinetix_credentials` prop).
     *
     * @return array{enabled: bool, requiresChange: bool, mustChange: bool, expired: bool, expiring: bool, daysUntilExpiry: ?int, changeUrl: ?string}
     */
    public function state(mixed $user): array
    {
        if (! $this->enabled() || ! $user instanceof Model) {
            return [
                'enabled'         => false,
                'requiresChange'  => false,
                'mustChange'      => false,
                'expired'         => false,
                'expiring'        => false,
                'daysUntilExpiry' => null,
                'changeUrl'       => null,
            ];
        }

        return [
            'enabled'         => true,
            'requiresChange'  => $this->requiresChange($user),
            'mustChange'      => $this->mustChange($user),
            'expired'         => $this->isExpired($user),
            'expiring'        => $this->isExpiring($user),
            'daysUntilExpiry' => $this->daysUntilExpiry($user),
            'changeUrl'       => route('kinetix.password.change.show'),
        ];
    }
}
