<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Features\FeatureManager;
use Happones\Kinetix\Features\KinetixFeatures;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;

class FeatureFlagsTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.features.enabled', true);
        $app['config']->set('kinetix.features.driver', 'native');
    }

    public function test_native_driver_resolves_bool_and_closure_flags(): void
    {
        $manager = app(FeatureManager::class);

        $manager->define('new-dashboard', true);
        $manager->define('legacy', false);
        $manager->define('beta', fn ($scope) => is_object($scope) && ($scope->plan ?? null) === 'pro');

        $this->assertTrue($manager->active('new-dashboard'));
        $this->assertFalse($manager->active('legacy'));
        $this->assertTrue($manager->inactive('legacy'));

        // Plan-gating shape: the resolver receives the scope.
        $this->assertTrue($manager->active('beta', (object) ['plan' => 'pro']));
        $this->assertFalse($manager->active('beta', (object) ['plan' => 'free']));
    }

    public function test_all_returns_a_resolved_boolean_map(): void
    {
        KinetixFeatures::define('a', true);
        KinetixFeatures::define('b', false);

        $this->assertSame(['a' => true, 'b' => false], KinetixFeatures::all());
    }

    public function test_middleware_404s_when_the_flag_is_inactive(): void
    {
        app(FeatureManager::class)->define('on', true);
        app(FeatureManager::class)->define('off', false);

        Route::get('/_test/on', fn () => 'ok')->middleware('kinetix.feature:on');
        Route::get('/_test/off', fn () => 'ok')->middleware('kinetix.feature:off');

        $this->get('/_test/on')->assertOk();
        $this->get('/_test/off')->assertNotFound();
    }
}
