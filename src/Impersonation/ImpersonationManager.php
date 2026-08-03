<?php

declare(strict_types=1);

namespace Happones\Kinetix\Impersonation;

use Happones\Kinetix\Activity\KinetixActivity;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

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

        // Built-in guard: never impersonate a super-admin unless you are one.
        $superAdmin = (string) config('kinetix.permissions.super_admin_role', 'super-admin');

        if ($superAdmin !== '' && method_exists($target, 'hasRole')) {
            if ($target->hasRole($superAdmin)) {
                return method_exists($impersonator, 'hasRole') && $impersonator->hasRole($superAdmin);
            }
        } elseif ($superAdmin !== '' && config('kinetix.permissions.enabled', false)) {
            // Roles are in play for this app, yet this target can't be inspected
            // for them — so the super-admin guard can't be evaluated at all. Fail
            // closed instead of silently waving it through. (A host with no roles
            // system has nothing to escalate, so it isn't affected.)
            return false;
        }

        // Impersonating a session is inheriting it. If the target can grant roles
        // and permissions and the impersonator cannot, impersonation becomes a
        // laundering route from `users.impersonate` into full role management —
        // so a privilege the impersonator lacks makes the target off-limits.
        return $this->targetHoldsNoUnheldEscalation($impersonator, $target);
    }

    /**
     * Whether the target is free of escalation-capable permissions the
     * impersonator doesn't already hold.
     */
    protected function targetHoldsNoUnheldEscalation(Authenticatable $impersonator, Authenticatable $target): bool
    {
        $protected = config('kinetix.impersonation.protected_permissions', ['roles.manage']);

        if (! is_array($protected) || $protected === [] || ! method_exists($target, 'hasPermissionTo')) {
            return true;
        }

        foreach ($protected as $permission) {
            $permission = (string) $permission;

            // A permission the host never registered can't be held by anyone;
            // hasPermissionTo() throws on unknown names, so treat it as absent.
            try {
                if (! $target->hasPermissionTo($permission)) {
                    continue;
                }

                if (! method_exists($impersonator, 'hasPermissionTo') || ! $impersonator->hasPermissionTo($permission)) {
                    return false;
                }
            } catch (Throwable) {
                continue;
            }
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
