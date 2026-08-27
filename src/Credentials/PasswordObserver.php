<?php

declare(strict_types=1);

namespace Happones\Kinetix\Credentials;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Keeps the password policy's state true no matter HOW a password changes.
 *
 * A password can be set from Fortify, a reset link, a seeder, an admin screen
 * or `tinker`, and a policy that only holds on the one path Kinetix owns is a
 * policy with a hole in it. So the bookkeeping — stamping
 * `password_changed_at`, clearing the forced-change flag, appending to the
 * history — hangs off the model's own events instead of a call site.
 *
 * Nothing here needs the host to remember anything.
 */
class PasswordObserver
{
    /**
     * Whether the users table actually carries the policy columns. Memoized
     * because it would otherwise be a schema query on every user write; the
     * service provider clears it at the start of each request/job.
     */
    protected static ?bool $columnsPresent = null;

    public function __construct(protected PasswordPolicy $policy) {}

    /**
     * A brand-new account starts its clock now, so expiry applies to accounts
     * created AFTER the policy was switched on. Accounts that predate it keep
     * a null stamp and are treated as current until their next change — see
     * {@see PasswordPolicy::expiresAt()}.
     */
    public function created(Model $user): void
    {
        if (! $this->applies($user) || ! filled($user->getAttribute('password'))) {
            return;
        }

        $this->stamp($user, keepForcedChange: (bool) $user->getAttribute('must_change_password'));
        $this->policy->record($user);
    }

    public function updated(Model $user): void
    {
        if (! $this->applies($user) || ! $user->wasChanged('password')) {
            return;
        }

        // Issuing a temporary credential changes the password AND flags it for
        // replacement in the same save. Clearing the flag here would undo that
        // in the same breath, so a flag set by this very write is respected.
        $keep = $user->wasChanged('must_change_password')
            && (bool) $user->getAttribute('must_change_password');

        $this->stamp($user, keepForcedChange: $keep);
        $this->policy->record($user);
    }

    /**
     * A deleted user's history goes with them — it is personal data whose only
     * purpose was to answer questions about that account.
     */
    public function deleted(Model $user): void
    {
        if (! $this->policy->enabled()) {
            return;
        }

        PasswordHistory::query()->where('user_id', $user->getKey())->delete();
    }

    protected function stamp(Model $user, bool $keepForcedChange): void
    {
        $attributes = ['password_changed_at' => now()];

        if (! $keepForcedChange) {
            $attributes['must_change_password'] = false;
        }

        // Quietly: this write must not re-enter the observer.
        $user->forceFill($attributes)->saveQuietly();
    }

    protected function applies(Model $user): bool
    {
        return $this->policy->enabled() && $this->hasColumns($user);
    }

    /**
     * The module can be enabled before its migration has run. Writing to a
     * column that isn't there would make every user save fail, so the observer
     * simply stands down instead — `kinetix:doctor` reports the mismatch.
     */
    protected function hasColumns(Model $user): bool
    {
        if (self::$columnsPresent !== null) {
            return self::$columnsPresent;
        }

        try {
            return self::$columnsPresent = Schema::connection($user->getConnectionName())
                ->hasColumn($user->getTable(), 'password_changed_at');
        } catch (Throwable) {
            return self::$columnsPresent = false;
        }
    }

    /**
     * Drop the memoized schema check (start of a request / queued job, and
     * after a migration in tests).
     */
    public static function flush(): void
    {
        self::$columnsPresent = null;
    }
}
