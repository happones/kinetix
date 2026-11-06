<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Onceable;
use Inertia\PropsResolver;
use Throwable;

/**
 * The `kinetix_*` shared props the browser keeps between visits.
 *
 * Every Inertia visit re-sends every shared prop. On inertia-laravel 3 a prop
 * can instead be sent once and kept by the client, which echoes its key back
 * on the next visit; the server then skips the prop entirely — no query, no
 * payload. Kinetix does that for the props listed in
 * `kinetix.shared_props.once`, under a FINGERPRINTED key: the user, the team's
 * route key, the locale, the prop's own config section and a per-session
 * version. Any of them changing produces a new key, and the prop is sent again.
 *
 * The session version moves after every successful write to a Kinetix module
 * whose state a kept prop shows ({@see self::SOURCE_MODULES}, through the
 * `kinetix.shared-props` middleware), and when the app calls {@see refresh()}
 * after changing that state itself. A full page load, `once_ttl` and a partial
 * reload that names the prop always resolve it.
 *
 * On inertia-laravel 2 nothing changes: every prop is resolved on every visit.
 */
final class KinetixSharedProps
{
    /**
     * Modules whose successful writes bump the version.
     *
     * @internal
     */
    public const SOURCE_MODULES = ['tours', 'permissions', 'membership', 'features', 'onboarding', 'accessibility', 'impersonation'];

    /**
     * Props that are never kept, even when listed: they change on their own
     * (toasts, alerts, the bell, presence) or carry security state.
     *
     * @internal
     */
    public const NEVER_ONCE = [
        'kinetix_config',
        'kinetix_toast',
        'kinetix_alerts',
        'kinetix_flash',
        'kinetix_dismissals',
        'kinetix_notifications',
        'kinetix_impersonation',
        'kinetix_presence',
        'kinetix_confidential',
        'kinetix_announcements',
        'kinetix_credentials',
        // Billing state changes from provider webhooks, outside the user's
        // session: no version bump could keep a kept copy fresh. Entitlements
        // compose the plan, so the same holds for them.
        'kinetix_billing',
        'kinetix_entitlements',
    ];

    /**
     * The session key holding the version.
     *
     * @internal
     */
    public const SESSION_KEY = 'kinetix_shared_props_version';

    /**
     * Re-send every kept prop on the user's next visit. Call it after changing,
     * outside Kinetix, state a kept prop shows (a role, a plan, a tour).
     * Without a session there is nothing to bump: kept props then refresh on a
     * user, team, locale or config change and after `once_ttl`.
     */
    public static function refresh(?Request $request = null): void
    {
        $request ??= request();

        if ($request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, Str::random(8));
        }
    }

    /**
     * Whether the installed inertia-laravel can keep a shared prop between
     * visits (version 3: the props resolver and once props).
     */
    public static function onceSupported(): bool
    {
        return class_exists(PropsResolver::class) && interface_exists(Onceable::class);
    }

    /**
     * Wrap a shared prop's resolver: a once prop under its fingerprinted key
     * when supported and `$prop` is listed in `kinetix.shared_props.once`,
     * else the resolved value. Called INSIDE the share closure, so the key is
     * computed per request.
     *
     * @internal
     *
     * @param list<string>      $configSections `kinetix.*` sections whose values are part of the key
     * @param callable(): mixed $resolve
     */
    public static function once(string $prop, array $configSections, callable $resolve): mixed
    {
        if (! self::enabledFor($prop)) {
            return $resolve();
        }

        $once = Inertia::once($resolve)->as(self::key($prop, $configSections));
        $ttl  = config('kinetix.shared_props.once_ttl', 3600);

        if (is_numeric($ttl) && (int) $ttl > 0) {
            $once->until((int) $ttl);
        }

        return $once;
    }

    /**
     * Whether `$prop` is kept between visits in this app.
     *
     * @internal
     */
    public static function enabledFor(string $prop): bool
    {
        if (! self::onceSupported() || in_array($prop, self::NEVER_ONCE, true)) {
            return false;
        }

        return in_array($prop, self::configured(), true);
    }

    /**
     * The listed props that are never kept: listing them is a mistake.
     *
     * @internal Diagnostics (`kinetix:doctor`).
     *
     * @return list<string>
     */
    public static function ignored(): array
    {
        return array_values(array_intersect(self::configured(), self::NEVER_ONCE));
    }

    /**
     * The fingerprinted key: `{prop}@{16 hex}`. Nothing secret goes in.
     *
     * @internal
     *
     * @param list<string> $configSections
     */
    public static function key(string $prop, array $configSections, ?Request $request = null): string
    {
        $request ??= request();
        $user = $request->user() ?? auth()->user();

        $parts = [
            $user instanceof Authenticatable ? $user::class.'#'.$user->getAuthIdentifier() : 'guest',
            (string) KinetixTeams::currentRouteKey($request),
            app()->getLocale(),
        ];

        foreach ($configSections as $section) {
            $parts[] = $section.':'.self::fingerprint(config("kinetix.{$section}"));
        }

        $parts[] = $request->hasSession() ? (string) $request->session()->get(self::SESSION_KEY, '0') : '-';

        return $prop.'@'.substr(hash('xxh3', implode('|', $parts)), 0, 16);
    }

    /**
     * @return list<string>
     */
    private static function configured(): array
    {
        return array_values(array_filter(
            (array) config('kinetix.shared_props.once', []),
            static fn (mixed $value): bool => is_string($value),
        ));
    }

    private static function fingerprint(mixed $value): string
    {
        try {
            return (string) crc32(serialize($value));
        } catch (Throwable) {
            // A closure in config (it breaks config:cache anyway).
            return (string) crc32((string) json_encode($value));
        }
    }
}
