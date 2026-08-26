<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class HelpScreenshotsCommandTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.help.enabled', true);
        $app['config']->set('kinetix.filesystem.disk', 'local');
        $app['config']->set('kinetix.help.screenshots.pages', [
            'dashboard' => '/dashboard',
            'products'  => ['path' => '/{team}/products', 'delay' => 1200],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('framework/kinetix-help-screenshots'));

        parent::tearDown();
    }

    public function test_runs_the_node_runner_with_a_manifest_and_uploads_the_pngs(): void
    {
        Storage::fake('local');
        Process::fake();

        // Simulate the runner having produced a PNG before upload runs.
        $outDir = storage_path('framework/kinetix-help-screenshots');
        File::ensureDirectoryExists($outDir);
        File::put("{$outDir}/dashboard.png", 'png-bytes');

        $this->artisan('kinetix:help-screenshots')->assertSuccessful();

        Process::assertRan(function (PendingProcess $process): bool {
            $command = $process->command;

            return is_array($command)
                && $command[0] === 'node'
                && str_ends_with((string) $command[1], 'help-screenshots.mjs')
                && str_ends_with((string) $command[2], 'manifest.json');
        });

        // The manifest carries the configured pages (name/path/overrides).
        $manifest = json_decode((string) File::get("{$outDir}/manifest.json"), true);
        $this->assertSame('dashboard', $manifest['pages'][0]['name']);
        $this->assertSame('/{team}/products', $manifest['pages'][1]['path']);
        $this->assertSame(1200, $manifest['pages'][1]['delay']);
        $this->assertSame(['width' => 1440, 'height' => 900], $manifest['viewport']);

        // The PNG landed on the configured disk under the prefix.
        Storage::disk('local')->assertExists('help/screenshots/dashboard.png');
        // ...and the local copy was removed (no --keep-local).
        $this->assertFileDoesNotExist("{$outDir}/dashboard.png");
    }

    public function test_only_filters_the_captured_pages(): void
    {
        Storage::fake('local');
        Process::fake();

        $outDir = storage_path('framework/kinetix-help-screenshots');
        File::ensureDirectoryExists($outDir);
        File::put("{$outDir}/products.png", 'png');

        $this->artisan('kinetix:help-screenshots', ['--only' => 'products'])->assertSuccessful();

        $manifest = json_decode((string) File::get("{$outDir}/manifest.json"), true);
        $this->assertCount(1, $manifest['pages']);
        $this->assertSame('products', $manifest['pages'][0]['name']);
    }

    public function test_a_failed_node_run_prints_the_manual_command(): void
    {
        Process::fake([
            '*' => Process::result(exitCode: 1, errorOutput: 'playwright missing'),
        ]);

        $this->artisan('kinetix:help-screenshots')
            ->expectsOutputToContain('npm i -D playwright')
            ->assertFailed();
    }

    public function test_fails_early_without_configured_pages(): void
    {
        config(['kinetix.help.screenshots.pages' => []]);

        $this->artisan('kinetix:help-screenshots')
            ->expectsOutputToContain('kinetix.help.screenshots.pages')
            ->assertFailed();
    }

    public function test_the_capture_directory_ignores_its_own_contents(): void
    {
        Storage::fake('local');
        Process::fake();

        $outDir = storage_path('framework/kinetix-help-screenshots');
        File::ensureDirectoryExists($outDir);
        File::put($outDir.'/dashboard.png', 'png');

        $this->artisan('kinetix:help-screenshots');

        // Every sibling directory Laravel keeps under storage/framework ships
        // this pair; ours is created by the command, so without it the captured
        // PNGs are only kept out of git by the host's root .gitignore — and
        // `--keep-local` or a failed upload leaves them behind.
        $ignore = $outDir.'/.gitignore';
        $this->assertFileExists($ignore);
        $this->assertSame("*\n!.gitignore\n", File::get($ignore));
    }

    public function test_the_ignore_file_is_not_rewritten_if_the_host_customized_it(): void
    {
        Storage::fake('local');
        Process::fake();

        $outDir = storage_path('framework/kinetix-help-screenshots');
        File::ensureDirectoryExists($outDir);
        File::put($outDir.'/.gitignore', "# mine\n*\n");
        File::put($outDir.'/dashboard.png', 'png');

        $this->artisan('kinetix:help-screenshots');

        $this->assertSame("# mine\n*\n", File::get($outDir.'/.gitignore'));
    }
}
