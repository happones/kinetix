<?php

declare(strict_types=1);

namespace Happones\Kinetix\Impersonation;

use Happones\Kinetix\Activity\KinetixActivity;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Drives "log in as user": swaps the authenticated user while remembering the
 * original in the session, with an escalation guard and an audit trail. The
 * `users.impersonate` ability (checked by the controller) governs who may
 * impersonate; this guard prevents the catastrophic case (impersonating a
 * super-admin unless you are one).
 */
class ImpersonationManager
{
    protected string $sessionKey = 'kinetix_impersonator_id';

    public function isImpersonating(): bool
    {
        return session()->has($this->sessionKey);
    }

    public function impersonatorId(): int|string|null
    {
        return session($this->sessionKey);
    }

    public function start(Authenticatable $target): void
    {
        $impersonator = auth()->user();

        abort_if($impersonator === null, 403);
        abort_unless($this->canImpersonate($impersonator, $target), 403, 'You cannot impersonate this user.');

        $this->log('impersonate.start', $target, $impersonator);

        session()->put($this->sessionKey, $impersonator->getAuthIdentifier());

        auth()->login($target);
    }

    public function stop(): void
    {
        if (! $this->isImpersonating()) {
            return;
        }

        $original = auth()->getProvider()->retrieveById($this->impersonatorId());

        session()->forget($this->sessionKey);

        if ($original !== null) {
            auth()->login($original);
            $this->log('impersonate.stop', $original, $original);
        }
    }

    public function canImpersonate(Authenticatable $impersonator, Authenticatable $target): bool
    {
        if ($impersonator->getAuthIdentifier() === $target->getAuthIdentifier()) {
            return false;
        }

        $resolver = config('kinetix.impersonation.can_impersonate');

        if (is_callable($resolver)) {
            return (bool) $resolver($impersonator, $target);
        }

        // Built-in guard: never impersonate a super-admin unless you are one.
        $superAdmin = (string) config('kinetix.permissions.super_admin_role', 'super-admin');

        if ($superAdmin !== '' && method_exists($target, 'hasRole') && $target->hasRole($superAdmin)) {
            return method_exists($impersonator, 'hasRole') && $impersonator->hasRole($superAdmin);
        }

        return true;
    }

    protected function log(string $event, Authenticatable $subject, Authenticatable $causer): void
    {
        if (! config('kinetix.activity.enabled', false)) {
            return;
        }

        KinetixActivity::log(
            $event,
            $subject instanceof Model ? $subject : null,
            [],
            $causer instanceof Model ? $causer : null,
        );
    }
}
