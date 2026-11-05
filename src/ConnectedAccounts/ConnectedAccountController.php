<?php

declare(strict_types=1);

namespace Happones\Kinetix\ConnectedAccounts;

use Happones\Kinetix\Data\ConnectedAccountData;
use Happones\Kinetix\Support\ConfigCallback;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rules\Password;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

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
    public function redirect(Request $request): SymfonyRedirect
    {
        // By name: under the team prefix (`{current_team}`) a positional
        // argument would receive the team, not the provider.
        $provider = (string) $request->route('provider');

        $this->user($request);
        $this->ensureProvider($provider);

        $driver = Socialite::driver($provider);

        // @phpstan-ignore method.notFound (redirectUrl() lives on the concrete provider)
        return $driver->redirectUrl($this->linkCallbackUrl($request, $provider))->redirect();
    }

    /**
     * Link callback: attach the provider identity to the current user.
     */
    public function callback(Request $request): RedirectResponse
    {
        // By name: under the team prefix (`{current_team}`) a positional
        // argument would receive the team, not the provider.
        $provider = (string) $request->route('provider');

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
    public function loginRedirect(Request $request): SymfonyRedirect
    {
        // By name: under the team prefix (`{current_team}`) a positional
        // argument would receive the team, not the provider.
        $provider = (string) $request->route('provider');

        $this->ensureLoginEnabled();
        $this->ensureProvider($provider);

        return Socialite::driver($provider)->redirect();
    }

    /**
     * Login callback: resolve or create the user, link the identity, then
     * sign in, or hand off to the two-factor challenge first.
     */
    public function loginCallback(Request $request): SymfonyResponse
    {
        // By name: under the team prefix (`{current_team}`) a positional
        // argument would receive the team, not the provider.
        $provider = (string) $request->route('provider');

        $this->ensureLoginEnabled();
        $this->ensureProvider($provider);

        $redirect = (string) config('kinetix.connected_accounts.login_redirect', '/');
        $failure  = (string) config('kinetix.connected_accounts.login_failure_redirect', '/login');

        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (\Throwable) {
            return redirect($failure)->with('error', __('kinetix.connected_account_failed'));
        }

        $label = app(ConnectedAccountProviderRegistry::class)->all()[$provider]['label'] ?? $provider;

        try {
            $user = $this->manager->resolveLoginUser($provider, $socialUser);
        } catch (SignInToLinkException) {
            return redirect($failure)->with('error', __('kinetix.connected_account_sign_in_to_link', ['provider' => $label]));
        }

        if ($user === null) {
            if (! $this->manager->mayCreateLoginUser($provider, $socialUser)) {
                return redirect($failure)->with('error', __('kinetix.connected_account_email_unverified', ['provider' => $label]));
            }

            $user = $this->manager->createLoginUser($provider, $socialUser);
        }

        if ($user instanceof Model) {
            try {
                $this->manager->link($user, $provider, $socialUser);
            } catch (AccountAlreadyLinkedException) {
                return redirect($failure)->with('error', __('kinetix.connected_account_taken'));
            }
        }

        $challenge = $this->twoFactorChallenge($request, $user, $redirect);

        if ($challenge !== null) {
            return $challenge;
        }

        Auth::login($user, remember: true);

        return redirect()->intended($redirect);
    }

    /**
     * The two-factor hand-off for a provider login, or null when the user
     * signs in straight away. A `connected_accounts.two_factor_challenge`
     * callback owns the decision; otherwise a user with two-factor enabled
     * goes through the host's `two-factor.login` challenge, exactly as a
     * password login would, and is refused when there is no such route.
     */
    protected function twoFactorChallenge(Request $request, Authenticatable $user, string $redirect): ?SymfonyResponse
    {
        $callback = ConfigCallback::resolve(config('kinetix.connected_accounts.two_factor_challenge'));

        if ($callback !== null) {
            $response = $callback($user, $request, true);

            return $response instanceof SymfonyResponse ? $response : null;
        }

        if (! $this->usesTwoFactor($user)) {
            return null;
        }

        $failure = (string) config('kinetix.connected_accounts.login_failure_redirect', '/login');

        if (! Route::has('two-factor.login')) {
            return redirect($failure)->with('error', __('kinetix.connected_account_two_factor_required'));
        }

        $request->session()->put([
            'login.id'       => $user->getAuthIdentifier(),
            'login.remember' => true,
        ]);

        if (! $request->session()->has('url.intended')) {
            $request->session()->put('url.intended', url($redirect));
        }

        $event = 'Laravel\Fortify\Events\TwoFactorAuthenticationChallenged';

        if (class_exists($event)) {
            event(new $event($user));
        }

        return redirect()->route('two-factor.login');
    }

    /**
     * Whether the user has two-factor authentication turned on: a stored
     * secret, and a confirmation when the host's Fortify requires one.
     */
    protected function usesTwoFactor(Authenticatable $user): bool
    {
        if (! $user instanceof Model || blank($user->getAttribute('two_factor_secret'))) {
            return false;
        }

        $confirms = ['Laravel\Fortify\Fortify', 'confirmsTwoFactorAuthentication'];

        if (is_callable($confirms) && (bool) call_user_func($confirms)) {
            return $user->getAttribute('two_factor_confirmed_at') !== null;
        }

        return true;
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
    public function destroy(Request $request): JsonResponse
    {
        // By name: under the team prefix (`{current_team}`) a positional
        // argument would receive the team, not the account.
        $account = (string) $request->route('account');

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
