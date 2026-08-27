<?php

declare(strict_types=1);

namespace Happones\Kinetix\Credentials\Middleware;

use Closure;
use Happones\Kinetix\Credentials\PasswordPolicy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends a user whose password has expired — or who was handed a temporary one
 * — to the change-password screen before they can do anything else
 * (alias `kinetix.password`).
 *
 * Append it to the host's authenticated group, the same way
 * `kinetix.permissions.team` is appended:
 *
 *     $middleware->appendToGroup('web', \Happones\Kinetix\Credentials\Middleware\EnsurePasswordIsCurrent::class);
 *
 * ## The exemption list is the whole design
 *
 * A middleware that redirects everything to one screen will redirect that
 * screen to itself — the classic way this feature ships broken. So the change
 * routes are always exempt, and so is anything that lets a stuck user get out:
 * logging out, and by default the auth/verification routes. Add your own with
 * `credentials.passwords.except` (route names, `fnmatch` patterns allowed).
 *
 * JSON requests are answered with a 423 (Locked) carrying the reason instead
 * of a redirect, so an XHR doesn't silently receive an HTML login page.
 */
class EnsurePasswordIsCurrent
{
    /**
     * Always exempt, whatever the app configures: without these a user with an
     * expired password has no way to fix it or to leave.
     *
     * @var array<int, string>
     */
    protected const ALWAYS_EXEMPT = [
        'kinetix.password.change.show',
        'kinetix.password.change',
        'logout',
        'login',
        'password.*',
        'verification.*',
    ];

    public function __construct(protected PasswordPolicy $policy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $this->policy->requiresChange($user)) {
            return $next($request);
        }

        if ($this->isExempt($request)) {
            return $next($request);
        }

        $message = $this->policy->mustChange($user)
            ? (string) __('kinetix.password_must_change')
            : (string) __('kinetix.password_expired');

        if ($request->expectsJson()) {
            abort(423, $message);
        }

        return redirect()
            ->route('kinetix.password.change.show')
            ->with('kinetix_toast', $message);
    }

    protected function isExempt(Request $request): bool
    {
        /** @var array<int, string> $configured */
        $configured = (array) config('kinetix.credentials.passwords.except', []);

        foreach ([...self::ALWAYS_EXEMPT, ...$configured] as $pattern) {
            if ($request->routeIs($pattern) || $request->is(ltrim($pattern, '/'))) {
                return true;
            }
        }

        return false;
    }
}
