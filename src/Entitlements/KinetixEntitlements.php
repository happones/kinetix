<?php

declare(strict_types=1);

namespace Happones\Kinetix\Entitlements;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Static entry point for entitlements — the composition of the four gating
 * layers Kinetix already ships (feature flags, plan capabilities, plan usage
 * limits, role permissions) under one name.
 *
 * Declare them in a service provider, next to your permissions and flags:
 *
 *     KinetixEntitlements::define('projects.create')
 *         ->plan('projects')
 *         ->limit('projects', [ProjectCounter::class, 'for'])
 *         ->permission('projects.create');
 *
 * Then ask ONE question, anywhere:
 *
 *     KinetixEntitlements::allows('projects.create');        // bool
 *     KinetixEntitlements::check('projects.create')->reason; // WHY not
 *     Route::post(...)->middleware('kinetix.entitled:projects.create');
 *     <KinetixEntitled name="projects.create">…</KinetixEntitled>
 *
 * The layers keep working on their own — this does not replace `<KinetixCan>`,
 * `<KinetixPlanFeature>` or `<KinetixFeature>`. Reach for an entitlement when
 * a feature sits behind MORE THAN ONE of them, which is where hand-written
 * `&&` chains drift apart between the controller, the button and the menu.
 */
class KinetixEntitlements
{
    public static function registry(): EntitlementRegistry
    {
        return app(EntitlementRegistry::class);
    }

    public static function define(string $name): Entitlement
    {
        return static::registry()->define($name);
    }

    public static function check(string $name, ?Authenticatable $user = null): Verdict
    {
        return static::registry()->check($name, $user);
    }

    public static function allows(string $name, ?Authenticatable $user = null): bool
    {
        return static::registry()->allows($name, $user);
    }

    public static function denies(string $name, ?Authenticatable $user = null): bool
    {
        return static::registry()->denies($name, $user);
    }

    /**
     * Stop the request unless the entitlement allows it — the imperative twin
     * of the `kinetix.entitled` middleware, for use inside a controller:
     *
     *     KinetixEntitlements::authorize('projects.create');
     *
     * A plan/limit denial redirects to the upgrade page (or 403s when none is
     * configured); a flag denial 404s; a permission denial 403s.
     */
    public static function authorize(string $name, ?Authenticatable $user = null): void
    {
        $response = static::check($name, $user)->enforce();

        if ($response !== null) {
            abort($response);
        }
    }

    /**
     * @return array<string, Entitlement>
     */
    public static function all(): array
    {
        return static::registry()->all();
    }
}
