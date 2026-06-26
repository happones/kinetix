<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Features\FeatureManager;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Laravel\Pennant\PennantServiceProvider;

class FeatureFlagsPennantTest extends TestCase
{
    /**
     * @param  Application              $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), PennantServiceProvider::class];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.features.enabled', true);
        $app['config']->set('kinetix.features.driver', 'pennant');
        // In-memory store — no migration needed.
        $app['config']->set('pennant.default', 'array');
    }

    public function test_flags_resolve_through_pennant(): void
    {
        $manager = app(FeatureManager::class);
        $this->assertTrue($manager->usesPennant());

        $manager->define('p-on', fn () => true);
        $manager->define('p-off', fn () => false);

        $this->assertTrue($manager->active('p-on', 'team-1'));
        $this->assertFalse($manager->active('p-off', 'team-1'));

        $all = $manager->all('team-1');
        $this->assertTrue($all['p-on']);
        $this->assertFalse($all['p-off']);
    }
}
