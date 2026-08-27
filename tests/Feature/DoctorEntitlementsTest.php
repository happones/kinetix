<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Entitlements\EntitlementRegistry;
use Happones\Kinetix\Entitlements\KinetixEntitlements;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Gate;

/**
 * Every way of misconfiguring an entitlement denies SILENTLY — a button that
 * simply never renders, with nothing in the logs. `kinetix:doctor` is where
 * those surface.
 */
class DoctorEntitlementsTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
    }

    protected function tearDown(): void
    {
        app(EntitlementRegistry::class)->reset();

        parent::tearDown();
    }

    public function test_nothing_is_reported_when_no_entitlements_are_declared(): void
    {
        $this->artisan('kinetix:doctor')
            ->doesntExpectOutputToContain('Entitlements')
            ->assertSuccessful();
    }

    public function test_declared_entitlements_with_the_module_off_is_an_error(): void
    {
        config()->set('kinetix.entitlements.enabled', false);
        KinetixEntitlements::define('projects.create');

        // The server still enforces them — but the prop is empty, so the whole
        // gated UI disappears with no error anywhere.
        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('silently denies')
            ->assertFailed();
    }

    public function test_enabled_entitlements_are_listed(): void
    {
        config()->set('kinetix.entitlements.enabled', true);
        KinetixEntitlements::define('projects.create');
        KinetixEntitlements::define('alerts.discord');

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('2 declared')
            ->assertSuccessful();
    }

    public function test_a_permission_layer_naming_an_unknown_ability_is_a_warning(): void
    {
        config()->set('kinetix.entitlements.enabled', true);
        KinetixEntitlements::define('projects.create')->permission('projects.create');

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('deny for everyone')
            ->assertSuccessful();
    }

    public function test_a_defined_gate_ability_is_not_flagged(): void
    {
        config()->set('kinetix.entitlements.enabled', true);
        Gate::define('projects.create', fn (): bool => true);
        KinetixEntitlements::define('projects.create')->permission('projects.create');

        $this->artisan('kinetix:doctor')
            ->doesntExpectOutputToContain('deny for everyone')
            ->assertSuccessful();
    }

    public function test_plan_layers_with_billing_off_are_a_warning(): void
    {
        config()->set('kinetix.entitlements.enabled', true);
        config()->set('kinetix.billing.enabled', false);
        KinetixEntitlements::define('api.use')->plan('api');

        // The layer is skipped (fail open), so the declaration reads as gated
        // while gating nothing at all.
        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('gate nothing')
            ->assertSuccessful();
    }

    public function test_plan_layers_with_billing_on_are_not_flagged(): void
    {
        config()->set('kinetix.entitlements.enabled', true);
        config()->set('kinetix.billing.enabled', true);
        KinetixEntitlements::define('api.use')->plan('api');

        $this->artisan('kinetix:doctor')
            ->doesntExpectOutputToContain('gate nothing')
            ->assertSuccessful();
    }
}
