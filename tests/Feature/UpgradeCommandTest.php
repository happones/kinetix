<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Support\ComposerHook;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class UpgradeCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        // The testbench skeleton is shared — leave no published files behind.
        // (The components tag also writes composables/stores/types, and a stale
        // copy there makes the drift detection report files nobody touched.)
        File::deleteDirectory(resource_path('js/components/kinetix'));
        File::deleteDirectory(resource_path('js/composables'));
        File::deleteDirectory(resource_path('js/stores'));
        File::deleteDirectory(resource_path('js/types'));
        File::delete(lang_path('en/kinetix.php'));

        parent::tearDown();
    }

    public function test_it_skips_targets_that_were_never_published(): void
    {
        $this->artisan('kinetix:upgrade')
            ->expectsOutputToContain('nothing to upgrade')
            ->assertSuccessful();

        $this->assertDirectoryDoesNotExist(resource_path('js/components/kinetix'));
    }

    public function test_it_republishes_adopted_targets_with_force(): void
    {
        // Simulate a previous publish, then a local drift.
        File::ensureDirectoryExists(resource_path('js/components/kinetix'));
        File::put(resource_path('js/components/kinetix/KinetixToaster.vue'), 'STALE LOCAL COPY');

        $this->artisan('kinetix:upgrade')
            ->expectsOutputToContain('Kinetix upgraded: components')
            ->assertSuccessful();

        // The stale copy was overwritten with the package version.
        $published = File::get(resource_path('js/components/kinetix/KinetixToaster.vue'));
        $this->assertNotSame('STALE LOCAL COPY', $published);
        $this->assertStringContainsString('<script', $published);
    }

    public function test_vue_i18n_generate_receives_the_configured_options(): void
    {
        // Regression: multi-locale apps compile per-locale files, but the
        // upgrade hook ran a bare `vue-i18n:generate` — regenerating the
        // single-file bundle they don't import and leaving the imported files
        // stale (raw kinetix.* keys in the UI after every composer update).
        config(['kinetix.translations.vue_i18n_options' => ['--multi-locales' => true]]);

        $captured = null;
        Artisan::command('vue-i18n:generate {--multi-locales}', function () use (&$captured): void {
            /** @var Command $this */
            $captured = $this->option('multi-locales');
        });

        File::ensureDirectoryExists(lang_path('en'));
        File::put(lang_path('en/kinetix.php'), "<?php return [];\n");

        $this->artisan('kinetix:upgrade')->assertSuccessful();

        $this->assertTrue($captured);
    }

    public function test_composer_hook_is_registered_once_and_idempotently(): void
    {
        $path = sys_get_temp_dir().'/kinetix-composer-'.uniqid().'.json';
        file_put_contents($path, json_encode([
            'name'    => 'acme/app',
            'scripts' => ['post-autoload-dump' => ['@php artisan package:discover --ansi']],
        ]));

        try {
            $this->assertTrue(ComposerHook::ensure($path, 'post-autoload-dump', '@php artisan kinetix:upgrade'));
            // Second call is a no-op.
            $this->assertFalse(ComposerHook::ensure($path, 'post-autoload-dump', '@php artisan kinetix:upgrade'));

            $composer = json_decode((string) file_get_contents($path), true);
            $this->assertSame(
                ['@php artisan package:discover --ansi', '@php artisan kinetix:upgrade'],
                $composer['scripts']['post-autoload-dump'],
            );
        } finally {
            @unlink($path);
        }
    }

    public function test_composer_hook_creates_the_event_when_missing(): void
    {
        $path = sys_get_temp_dir().'/kinetix-composer-'.uniqid().'.json';
        file_put_contents($path, json_encode(['name' => 'acme/app']));

        try {
            $this->assertTrue(ComposerHook::ensure($path, 'post-autoload-dump', '@php artisan kinetix:upgrade'));

            $composer = json_decode((string) file_get_contents($path), true);
            $this->assertSame(['@php artisan kinetix:upgrade'], $composer['scripts']['post-autoload-dump']);
        } finally {
            @unlink($path);
        }
    }
}
