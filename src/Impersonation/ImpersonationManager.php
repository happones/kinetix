<?php

declare(strict_types=1);

namespace Happones\Kinetix\Impersonation;

use Happones\Kinetix\Activity\KinetixActivity;
use Happones\Kinetix\Permissions\SuperAdmin;
use Happones\Kinetix\Permissions\TakeoverGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Drives "log in as user": swaps the authenticated user while remembering the
 * original in the session, with an escalation guard and an audit trail. The
 * `users.impersonate` ability (checked by the controller) governs who may
 * impersonate; this guard prevents privilege gain — impersonating a super-admin
 * unless you are one, or anyone holding an escalation-capable permission you
 * don't already hold.
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

        return $this->passesBuiltInGuards($impersonator, $target);
    }

    /**
     * The built-in escalation guards ({@see TakeoverGuard}): never impersonate
     * a super-admin unless you are one; under teams, only a user whose every
     * team is also yours; and never a target who, in any of their teams,
     * holds a protected permission (`impersonation.protected_permissions`)
     * you don't hold there, or owns a team you don't own. A
     * `can_impersonate` callback REPLACES these, so a callback that narrows
     * the rule should call this first and keep its verdict:
     *
     *     if (! app(ImpersonationManager::class)->passesBuiltInGuards($impersonator, $target)) {
     *         return false;
     *     }
     */
    public function passesBuiltInGuards(Authenticatable $impersonator, Authenticatable $target): bool
    {
        // Roles are in play for this app, yet this target can't be inspected
        // for them — so the super-admin guard can't be evaluated at all. Fail
        // closed instead of silently waving it through. (A host with no roles
        // system has nothing to escalate, so it isn't affected.)
        if (SuperAdmin::role() !== '' && ! method_exists($target, 'hasRole') && config('kinetix.permissions.enabled', false)) {
            return false;
        }

        $protected = config('kinetix.impersonation.protected_permissions', ['roles.manage']);

        return TakeoverGuard::allows($impersonator, $target, is_array($protected) ? $protected : []);
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
