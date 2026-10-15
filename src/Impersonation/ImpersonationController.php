<?php

declare(strict_types=1);

namespace Happones\Kinetix\Impersonation;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Starts and leaves impersonation. `start` is gated by the `users.impersonate`
 * ability (checked against the impersonating admin); `leave` is open — the
 * impersonated user must be able to get back out. The target is resolved by id
 * through the auth provider, so the package needs no User model reference.
 */
class ImpersonationController
{
    public function start(Request $request, ImpersonationManager $manager): RedirectResponse
    {
        Gate::authorize('users.impersonate');

        $target = $this->resolveUser($request->route('user'));

        $manager->start($target);

        return redirect()->intended($this->destination('redirect_to', $target));
    }

    public function leave(Request $request, ImpersonationManager $manager): RedirectResponse
    {
        $manager->stop();

        $impersonator = $request->user();

        return redirect()->intended($this->destination('redirect_back', $impersonator instanceof Authenticatable ? $impersonator : null));
    }

    /**
     * `impersonation.redirect_to` / `redirect_back`: a URL, or a callable
     * array receiving the user the session now belongs to — the target after
     * starting, the admin after leaving — so a team app can land each on their
     * own team's pages.
     */
    protected function destination(string $key, ?Authenticatable $user): string
    {
        $destination = config("kinetix.impersonation.{$key}", '/');

        if (is_array($destination) && is_callable($destination)) {
            return (string) $destination($user);
        }

        return is_string($destination) && $destination !== '' ? $destination : '/';
    }

    protected function resolveUser(mixed $id): Authenticatable
    {
        $user = auth()->getProvider()->retrieveById($id);

        abort_if($user === null, 404, 'User not found.');

        return $user;
    }
}
