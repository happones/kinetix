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

        $manager->start($this->resolveUser($request->route('user')));

        return redirect()->intended((string) config('kinetix.impersonation.redirect_to', '/'));
    }

    public function leave(Request $request, ImpersonationManager $manager): RedirectResponse
    {
        $manager->stop();

        return redirect()->intended((string) config('kinetix.impersonation.redirect_back', '/'));
    }

    protected function resolveUser(mixed $id): Authenticatable
    {
        $user = auth()->getProvider()->retrieveById($id);

        abort_if($user === null, 404, 'User not found.');

        return $user;
    }
}
