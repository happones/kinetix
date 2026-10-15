<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionServiceProvider;

class LocaleWithTeamsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), PermissionServiceProvider::class];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('kinetix.teams', true);
        $app['config']->set('kinetix.locale.enabled', true);
        $app['config']->set('kinetix.locale.locales', ['en' => 'English', 'es' => 'Español']);
    }

    public function test_a_guest_switches_the_language_without_a_team(): void
    {
        $this->post('/_kinetix/locale', ['locale' => 'es'])
            ->assertOk()
            ->assertJsonPath('locale', 'es');

        $this->assertSame('es', session('kinetix.locale'));
    }

    public function test_team_pages_keep_their_team_prefixed_switch(): void
    {
        $this->assertSame('{current_team}/_kinetix/locale', Route::getRoutes()->getByName('kinetix.locale.update')?->uri());
        $this->assertSame('_kinetix/locale', Route::getRoutes()->getByName('kinetix.locale.switch')?->uri());
    }
}
