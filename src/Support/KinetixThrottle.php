<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The named rate limiters behind Kinetix's sensitive endpoints: password
 * confirmations, work that queues jobs or sends mail, and provider calls.
 *
 * Each limiter reads its `'attempts,minutes'` config INSIDE the limiter, on
 * every request, so a config change applies to cached routes too. The budget
 * is per user (shared across tabs and devices; by IP for a guest), and every
 * password confirmation shares ONE budget, so rotating endpoints buys no
 * extra attempts. Every attempt counts, successful ones included.
 *
 * A throttled request answers a localized 429: JSON with `code: throttled`
 * and `retry_after` (plus an `errors.password` entry for the password
 * budget), or, for an Inertia visit, a 303 back with the message as a field
 * error.
 *
 * @internal
 */
final class KinetixThrottle
{
    /**
     * Limiter name => [config key under `kinetix.`, default, error field|null].
     *
     * @var array<string, array{0: string, 1: string, 2: string|null}>
     */
    public const LIMITERS = [
        'kinetix-password'             => ['password_confirmation.throttle', '5,1', 'password'],
        'kinetix-exports'              => ['exports.start_throttle', '10,1', null],
        'kinetix-gdpr-export'          => ['gdpr.export_throttle', '3,60', null],
        'kinetix-report-runs'          => ['reports_center.launch_throttle', '10,1', null],
        'kinetix-mail-test'            => ['mail_templates.test_throttle', '5,1', null],
        'kinetix-member-resend'        => ['membership.resend_throttle', '6,1', null],
        'kinetix-channels'             => ['channels.action_throttle', '10,1', null],
        'kinetix-team-search'          => ['team_switcher.search_throttle', '60,1', null],
        'kinetix-member-invite'        => ['membership.invite_throttle', '10,1', null],
        'kinetix-spotlight'            => ['spotlight.throttle', '60,1', null],
        'kinetix-webhook-test'         => ['webhooks.test_throttle', '6,1', null],
        'kinetix-flow-simulate'        => ['flows.simulate_throttle', '10,1', null],
        'kinetix-recompute'            => ['forms.recompute_throttle', '120,1', null],
        'kinetix-notification-routing' => ['notification_routing.preview_throttle', '30,1', null],
    ];

    /**
     * Register every limiter. Called from the provider's boot()
     * unconditionally and outside the route blocks: a cached route that
     * names a missing limiter would let `ThrottleRequests` read the name as
     * a limit of 0 attempts and answer every request with a 429.
     */
    public static function register(): void
    {
        foreach (array_keys(self::LIMITERS) as $name) {
            RateLimiter::for($name, static fn (Request $request): Limit => self::limitFor($name, $request));
        }
    }

    /** The `throttle` middleware string for a route definition. */
    public static function middleware(string $name): string
    {
        return 'throttle:'.$name;
    }

    /**
     * The `[attempts, minutes]` a configured value asks for; null when the
     * limit is off (`false`, `null`, `''`). A malformed value falls back to
     * the limiter's default.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function parse(mixed $value, string $default): ?array
    {
        if ($value === false || $value === null || $value === '') {
            return null;
        }

        return self::strictParse($value) ?? self::strictParse($default);
    }

    /**
     * The `[attempts, minutes]` of a well-formed value, or null when it isn't
     * one (doctor reports those). The `'attempts,minutes,prefix'` form older
     * configs used is accepted: the prefix kept route counters apart, which
     * every named limiter now does on its own, so it is ignored.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function strictParse(mixed $value): ?array
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || preg_match('/^\s*(\d+)\s*(?:,\s*(\d+)\s*(?:,\s*[\w.:-]+\s*)?)?$/', $value, $matches) !== 1) {
            return null;
        }

        $attempts = (int) $matches[1];
        $minutes  = isset($matches[2]) ? (int) $matches[2] : 1;

        if ($attempts < 1 || $minutes < 1) {
            return null;
        }

        return [$attempts, $minutes];
    }

    /** The limit one request gets from the named limiter. */
    public static function limitFor(string $name, Request $request): Limit
    {
        [$key, $default, $field] = self::LIMITERS[$name];

        $parsed = self::parse(config("kinetix.{$key}", $default), $default);

        if ($parsed === null) {
            return Limit::none();
        }

        [$attempts, $minutes] = $parsed;

        return Limit::perMinutes($minutes, $attempts)
            ->by($name.'|'.self::identity($request))
            ->response(static fn (Request $request, array $headers): Response => self::response($request, $headers, $field));
    }

    /** Whose budget a request spends: the user's, else the client IP's. */
    private static function identity(Request $request): string
    {
        $user = $request->user();

        if ($user instanceof Authenticatable) {
            return $user::class.'#'.$user->getAuthIdentifier();
        }

        return 'ip:'.$request->ip();
    }

    /**
     * The localized 429. Translated here, per request, never at boot.
     *
     * @param array<string, mixed> $headers
     */
    private static function response(Request $request, array $headers, ?string $field): Response
    {
        $seconds = max(1, (int) ($headers['Retry-After'] ?? 60));
        $message = __('kinetix.too_many_attempts', ['seconds' => $seconds]);

        if ($message === 'kinetix.too_many_attempts') {
            $message = __('kinetix::kinetix.too_many_attempts', ['seconds' => $seconds]);
        }

        $message = is_string($message) ? $message : 'Too many attempts.';

        if ($request->hasHeader('X-Inertia')) {
            return back(303)->withErrors([$field ?? 'throttle' => $message]);
        }

        $body = ['message' => $message, 'code' => 'throttled', 'retry_after' => $seconds];

        if ($field !== null) {
            $body['errors'] = [$field => [$message]];
        }

        return new JsonResponse($body, 429, $headers);
    }
}
