<?php

declare(strict_types=1);

namespace Happones\Kinetix\ConnectedAccounts;

use Happones\Kinetix\Data\ConnectedAccountData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

/**
 * Connected Accounts / social auth. Two flows:
 *  - Guest login/registration via a provider (opt-in, find-or-create + login).
 *  - Authenticated link/unlink management, plus a set-password endpoint for
 *    social-only users who never had a password.
 */
class ConnectedAccountController
{
    public function __construct(protected ConnectedAccountManager $manager) {}

    /**
     * The current user's linked accounts + the provider catalog (with a `linked`
     * flag) + whether the user has a password set.
     */
    public function index(Request $request): JsonResponse
    {
        $user     = $this->user($request);
        $accounts = $this->manager->for($user);
        $linked   = collect($accounts)->map(static fn (ConnectedAccountData $a): string => $a->provider)->all();

        $providers = collect(app(ConnectedAccountProviderRegistry::class)->all())
            ->map(static fn (array $config, string $key): array => [
                'key'    => $key,
                'label'  => $config['label'],
                'icon'   => $config['icon'],
                'color'  => $config['color'],
                'linked' => in_array($key, $linked, true),
            ])
            ->values();

        return response()->json([
            'accounts'    => $accounts,
            'providers'   => $providers,
            'hasPassword' => $this->manager->hasPassword($user),
        ]);
    }

    /**
     * Start the OAuth round-trip to link a provider to the current user.
     */
    public function redirect(Request $request, string $provider): SymfonyRedirect
    {
        $this->user($request);
        $this->ensureProvider($provider);

        $driver = Socialite::driver($provider);

        // @phpstan-ignore method.notFound (redirectUrl() lives on the concrete provider)
        return $driver->redirectUrl($this->linkCallbackUrl($request, $provider))->redirect();
    }

    /**
     * Link callback: attach the provider identity to the current user.
     */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        $user = $this->user($request);
        $this->ensureProvider($provider);

        $redirect = (string) config('kinetix.connected_accounts.redirect', '/');

        try {
            $driver = Socialite::driver($provider);

            // @phpstan-ignore method.notFound (redirectUrl() lives on the concrete provider)
            $socialUser = $driver->redirectUrl($this->linkCallbackUrl($request, $provider))->user();
        } catch (\Throwable) {
            return redirect($redirect)->with('error', __('kinetix.connected_account_failed'));
        }

        try {
            $this->manager->link($user, $provider, $socialUser);
        } catch (AccountAlreadyLinkedException) {
            return redirect($redirect)->with('error', __('kinetix.connected_account_taken'));
        }

        return redirect($redirect)->with('status', __('kinetix.connected_account_linked'));
    }

    /**
     * Start the OAuth round-trip for guest login/registration (opt-in).
     */
    public function loginRedirect(string $provider): SymfonyRedirect
    {
        $this->ensureLoginEnabled();
        $this->ensureProvider($provider);

        return Socialite::driver($provider)->redirect();
    }

    /**
     * Login callback: find-or-create the user, link the identity, log in.
     */
    public function loginCallback(Request $request, string $provider): RedirectResponse
    {
        $this->ensureLoginEnabled();
        $this->ensureProvider($provider);

        $redirect = (string) config('kinetix.connected_accounts.login_redirect', '/');
        $failure  = (string) config('kinetix.connected_accounts.login_failure_redirect', '/login');

        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (\Throwable) {
            return redirect($failure)->with('error', __('kinetix.connected_account_failed'));
        }

        $user = $this->manager->resolveLoginUser($provider, $socialUser)
            ?? $this->manager->createLoginUser($provider, $socialUser);

        if ($user instanceof Model) {
            $this->manager->link($user, $provider, $socialUser);
        }

        Auth::login($user, remember: true);

        return redirect()->intended($redirect);
    }

    /**
     * Set or change the current user's password. A social-only user (no password)
     * may set one without supplying a current password.
     */
    public function password(Request $request): JsonResponse
    {
        $user        = $this->user($request);
        $hasPassword = $this->manager->hasPassword($user);

        $rules = ['password' => ['required', 'confirmed', Password::defaults()]];

        if ($hasPassword) {
            $rules['current_password'] = ['required', 'current_password'];
        }

        $validated = $request->validate($rules);

        $this->manager->setPassword($user, $validated['password']);

        return response()->json(['status' => 'success', 'hasPassword' => true]);
    }

    /**
     * Unlink one of the current user's accounts.
     */
    public function destroy(Request $request, int|string $account): JsonResponse
    {
        $this->manager->unlink($this->user($request), $account);

        return response()->json(['status' => 'success']);
    }

    protected function user(Request $request): Model
    {
        $user = $request->user();

        abort_if(! $user instanceof Model, 401);

        return $user;
    }

    protected function ensureProvider(string $provider): void
    {
        abort_unless(app(ConnectedAccountProviderRegistry::class)->has($provider), 404);
    }

    protected function ensureLoginEnabled(): void
    {
        abort_unless((bool) config('kinetix.connected_accounts.login_enabled', false), 404);
    }

    /**
     * The link-callback URL for this provider, team-aware so it round-trips
     * inside the current team prefix when teams are enabled.
     */
    protected function linkCallbackUrl(Request $request, string $provider): string
    {
        $params = [];

        if (config('kinetix.teams', false) && $request->route('current_team') !== null) {
            $params['current_team'] = $request->route('current_team');
        }

        $params['provider'] = $provider;

        return route('kinetix.connected-accounts.callback', $params);
    }
}
