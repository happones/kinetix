<?php

declare(strict_types=1);

namespace Happones\Kinetix\Credentials;

use Happones\Kinetix\Credentials\Rules\NotAPreviousPassword;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The change-password screen the `kinetix.password` middleware sends people to,
 * and the endpoint behind it.
 *
 * Deliberately usable in both situations: a user FORCED here (expired, or a
 * temporary credential) and a user who simply chose to change their password.
 * The page is told which one it is so it can explain itself; the endpoint is
 * the same either way.
 */
class PasswordController
{
    public function __construct(protected PasswordPolicy $policy) {}

    public function show(Request $request): InertiaResponse
    {
        $user = $request->user();

        return Inertia::render((string) config('kinetix.credentials.passwords.view', 'Kinetix/PasswordChange'), [
            // The URL travels from the server like every other Kinetix action,
            // so the component never needs Ziggy.
            'action'          => route('kinetix.password.change'),
            'mustChange'      => $this->policy->mustChange($user),
            'expired'         => $this->policy->isExpired($user),
            'daysUntilExpiry' => $this->policy->daysUntilExpiry($user),
            'historyDepth'    => $this->policy->historyDepth(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_if(! $user instanceof Model, 403);

        $validated = $request->validate([
            'current_password' => [$this->requiresCurrentPassword($user) ? 'required' : 'nullable', 'string'],
            'password'         => ['required', 'confirmed', Password::defaults(), new NotAPreviousPassword($user)],
        ]);

        if ($this->requiresCurrentPassword($user)
            && ! Hash::check((string) $validated['current_password'], (string) $user->getAttribute('password'))) {
            throw ValidationException::withMessages([
                'current_password' => __('kinetix.password_current_incorrect'),
            ]);
        }

        // The observer stamps `password_changed_at`, clears the forced-change
        // flag and appends to the history — whatever route the change came in
        // through, including this one.
        $user->forceFill(['password' => Hash::make((string) $validated['password'])])->save();

        return redirect()
            ->intended((string) config('kinetix.credentials.passwords.redirect_after', '/'))
            ->with('kinetix_toast', (string) __('kinetix.password_changed'));
    }

    /**
     * Whether the user has to prove the current password.
     *
     * They do when they know it — the ordinary "change my password" case. They
     * do NOT when the account is on a TEMPORARY credential: an admin handed
     * that one over, it is not a secret the user chose, and demanding it back
     * adds a step without adding security. An EXPIRED password is still the
     * user's own, so it is still required.
     */
    protected function requiresCurrentPassword(Authenticatable|Model|null $user): bool
    {
        return ! $this->policy->mustChange($user);
    }
}
