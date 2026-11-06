<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\KinetixServiceProvider;
use Happones\Kinetix\Support\BootWarnings;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Log;
use ReflectionProperty;

/**
 * Boot-time misconfiguration warnings are logged once per boot, and only
 * where someone reads them (console processes, local): a production web
 * request boots the app every time, so it used to log the same line on every
 * request. `kinetix:doctor` reports them on demand.
 */
class BootWarningsTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Enabled without laravel/socialite's facade would warn; here the
        // membership `direct` provisioning without credentials does.
        $app['config']->set('kinetix.membership.enabled', true);
        $app['config']->set('kinetix.membership.provisioning', 'direct');
        $app['config']->set('kinetix.credentials.enabled', false);
    }

    private function runningInConsole(bool $console): void
    {
        (new ReflectionProperty(Application::class, 'isRunningInConsole'))->setValue($this->app, $console);
    }

    private function bootAgain(): void
    {
        (new KinetixServiceProvider($this->app))->boot();
    }

    public function test_a_console_boot_logs_each_warning_once(): void
    {
        Log::swap(\Mockery::spy(LogManager::class));

        $this->bootAgain();
        BootWarnings::warn('membership.direct_without_credentials', 'again');

        Log::shouldHaveReceived('warning')
            ->withArgs(static fn (string $message): bool => str_contains($message, 'provisioning'))
            ->once();
        Log::shouldNotHaveReceived('warning', ['again']);

        // The next boot (a new worker, the next artisan command) logs again.
        $this->bootAgain();

        Log::shouldHaveReceived('warning')
            ->withArgs(static fn (string $message): bool => str_contains($message, 'provisioning'))
            ->twice();
    }

    public function test_a_production_web_request_logs_nothing(): void
    {
        $this->runningInConsole(false);
        $this->app['env'] = 'production';
        Log::swap(\Mockery::spy(LogManager::class));

        $this->bootAgain();

        Log::shouldNotHaveReceived('warning');
    }

    public function test_a_local_web_request_logs_them(): void
    {
        $this->runningInConsole(false);
        $this->app['env'] = 'local';
        Log::swap(\Mockery::spy(LogManager::class));

        BootWarnings::flush();
        BootWarnings::warn('test.key', 'Kinetix: something is off.');

        Log::shouldHaveReceived('warning')->with('Kinetix: something is off.')->once();
    }
}
