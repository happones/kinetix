<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Permissions\PermissionRegistry;
use Happones\Kinetix\Resources\Resource;
use Happones\Kinetix\Support\DiscoveryManifest;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use ReflectionMethod;

/**
 * `kinetix:optimize` caches the classes the registries discover by scanning
 * directories, so production requests stop walking them. Without the
 * manifest every root is scanned, as always — development never goes stale.
 */
class DiscoveryManifestTest extends TestCase
{
    private const NAMESPACE = 'KinetixManifestProbe\\Resources';

    private static string $root;

    /** @var \Closure(string): void */
    private \Closure $autoloader;

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        self::$root = sys_get_temp_dir().'/kinetix-manifest-'.getmypid().'-'.uniqid();
        File::ensureDirectoryExists(self::$root.'/Resources');

        $app['config']->set('kinetix.permissions.discover_path', self::$root.'/Resources');
        $app['config']->set('kinetix.permissions.discover_namespace', self::NAMESPACE);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->autoloader = $this->autoload(...);
        spl_autoload_register($this->autoloader);
        DiscoveryManifest::clear();
        $this->writeResource('Alpha');
    }

    protected function tearDown(): void
    {
        DiscoveryManifest::clear();
        spl_autoload_unregister($this->autoloader);
        File::deleteDirectory(self::$root);

        parent::tearDown();
    }

    private function autoload(string $class): void
    {
        if (! str_starts_with($class, self::NAMESPACE.'\\')) {
            return;
        }

        $file = self::$root.'/Resources/'.substr($class, strlen(self::NAMESPACE) + 1).'.php';

        if (is_file($file)) {
            require_once $file;
        }
    }

    private function writeResource(string $name): void
    {
        // A per-test suffix: a class, once loaded, stays loaded for the process.
        $suffix = substr(md5(self::$root), 0, 8);
        File::put(self::$root."/Resources/{$name}{$suffix}.php", '<?php namespace '.self::NAMESPACE.'; class '.$name.$suffix.' extends \\'.Resource::class.' {}');
    }

    private function className(string $name): string
    {
        return self::NAMESPACE.'\\'.$name.substr(md5(self::$root), 0, 8);
    }

    /**
     * @return list<string>
     */
    private function discovered(string $directory = '', string $namespace = self::NAMESPACE): array
    {
        $registry = app(PermissionRegistry::class);
        $method   = new ReflectionMethod($registry, 'scanForSubclasses');

        return $method->invoke($registry, $directory !== '' ? $directory : self::$root.'/Resources', $namespace, Resource::class);
    }

    public function test_without_a_manifest_the_root_is_scanned(): void
    {
        $this->assertFalse(DiscoveryManifest::exists());
        $this->assertSame([$this->className('Alpha')], $this->discovered());

        $this->writeResource('Beta');

        $this->assertEqualsCanonicalizing([$this->className('Alpha'), $this->className('Beta')], $this->discovered());
    }

    public function test_optimize_writes_every_registry_root(): void
    {
        Artisan::call('kinetix:optimize');

        $this->assertFileExists(DiscoveryManifest::path());

        $manifest = require DiscoveryManifest::path();

        $this->assertSame(1, $manifest['version']);
        $this->assertSame(
            [$this->className('Alpha')],
            $manifest['entries'][DiscoveryManifest::key(self::$root.'/Resources', self::NAMESPACE, Resource::class)],
        );

        $keys = implode("\n", array_keys($manifest['entries']));
        $this->assertStringContainsString('Kinetix/Spotlight|App\\Kinetix\\Spotlight|Happones\\Kinetix\\Spotlight\\SpotlightSource', $keys);
        $this->assertStringContainsString('Kinetix/Reports|App\\Kinetix\\Reports|Happones\\Kinetix\\ReportsCenter\\Report', $keys);
    }

    public function test_with_a_manifest_new_classes_wait_for_the_next_optimize(): void
    {
        Artisan::call('kinetix:optimize');
        $this->writeResource('Beta');

        // The contract of `config:cache`: cached until the next optimize.
        $this->assertSame([$this->className('Alpha')], $this->discovered());

        Artisan::call('kinetix:optimize');

        $this->assertEqualsCanonicalizing([$this->className('Alpha'), $this->className('Beta')], $this->discovered());
    }

    public function test_a_root_missing_from_the_manifest_is_scanned(): void
    {
        Artisan::call('kinetix:optimize');

        $other = self::$root.'/Other';
        File::ensureDirectoryExists($other);
        File::copyDirectory(self::$root.'/Resources', $other);

        $this->assertFalse(DiscoveryManifest::covers($other, self::NAMESPACE, Resource::class));
        $this->assertSame([$this->className('Alpha')], $this->discovered($other));
    }

    public function test_a_cached_class_that_no_longer_exists_is_skipped(): void
    {
        DiscoveryManifest::write([
            DiscoveryManifest::key(self::$root.'/Resources', self::NAMESPACE, Resource::class) => [
                $this->className('Alpha'),
                self::NAMESPACE.'\\Deleted',
            ],
        ]);

        $this->assertSame([$this->className('Alpha')], $this->discovered());
    }

    public function test_optimize_clear_removes_the_manifest(): void
    {
        Artisan::call('kinetix:optimize');
        $this->assertTrue(DiscoveryManifest::exists());

        Artisan::call('kinetix:optimize-clear');

        $this->assertFileDoesNotExist(DiscoveryManifest::path());
        $this->assertFalse(DiscoveryManifest::exists());
    }

    public function test_laravels_optimize_commands_include_kinetix_when_supported(): void
    {
        if (! method_exists(ServiceProvider::class, 'optimizes')) {
            $this->markTestSkipped('This Laravel version has no provider optimize hooks.');
        }

        $this->assertSame('kinetix:optimize', ServiceProvider::$optimizeCommands['kinetix'] ?? null);
        $this->assertSame('kinetix:optimize-clear', ServiceProvider::$optimizeClearCommands['kinetix'] ?? null);
    }

    public function test_keys_are_relative_to_the_base_path(): void
    {
        $this->assertSame(
            'app/Kinetix/Resources|App\\Kinetix\\Resources|'.Resource::class,
            DiscoveryManifest::key(base_path('app/Kinetix/Resources/'), 'App\\Kinetix\\Resources\\', '\\'.Resource::class),
        );
    }

    public function test_doctor_warns_about_a_manifest_in_the_local_environment(): void
    {
        Artisan::call('kinetix:optimize');
        $this->app['env'] = 'local';

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('the discovery manifest exists in the local environment');
    }
}
