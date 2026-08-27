<?php

declare(strict_types=1);

namespace Happones\Kinetix\Credentials;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Static entry point for the password lifecycle.
 *
 *     KinetixPasswords::requiresChange($user);      // gate anything yourself
 *     KinetixPasswords::forceChange($user);         // after a breach
 *     $plain = KinetixPasswords::issueTemporary($user);   // shown ONCE
 *     KinetixPasswords::wasUsedBefore($user, $candidate);
 *
 * The bookkeeping (stamping the change, pruning the history, clearing the
 * forced-change flag) happens on its own through {@see PasswordObserver} —
 * these are for asking, and for the cases where you issue a credential.
 */
class KinetixPasswords
{
    public static function policy(): PasswordPolicy
    {
        return app(PasswordPolicy::class);
    }

    public static function enabled(): bool
    {
        return static::policy()->enabled();
    }

    /**
     * Whether this user must replace their password before using the app —
     * flagged (a temporary credential) or expired.
     */
    public static function requiresChange(mixed $user): bool
    {
        return static::policy()->requiresChange($user);
    }

    public static function isExpired(mixed $user): bool
    {
        return static::policy()->isExpired($user);
    }

    public static function expiresAt(mixed $user): ?Carbon
    {
        return static::policy()->expiresAt($user);
    }

    public static function daysUntilExpiry(mixed $user): ?int
    {
        return static::policy()->daysUntilExpiry($user);
    }

    public static function mustChange(mixed $user): bool
    {
        return static::policy()->mustChange($user);
    }

    public static function forceChange(Model $user): void
    {
        static::policy()->forceChange($user);
    }

    /**
     * Issue a temporary password and return the plaintext ONCE. It is hashed on
     * the way in and cannot be read back — hand it over now or issue a new one.
     */
    public static function issueTemporary(Model $user, ?string $plain = null): string
    {
        return static::policy()->issueTemporary($user, $plain);
    }

    public static function temporaryExpiresAt(mixed $user): ?Carbon
    {
        return static::policy()->temporaryExpiresAt($user);
    }

    public static function temporaryHasExpired(mixed $user): bool
    {
        return static::policy()->temporaryHasExpired($user);
    }

    public static function wasUsedBefore(mixed $user, string $candidate): bool
    {
        return static::policy()->wasUsedBefore($user, $candidate);
    }

    /**
     * Forget a user's password history (GDPR erasure, or resetting their state).
     */
    public static function forget(Model $user): void
    {
        static::policy()->forget($user);
    }
}
