<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Commands\InstallCommand;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * InstallCommand variant that skips the real `npm install` shell-out so the
 * command's file mutations can be tested in isolation.
 */
class TestableInstallCommand extends InstallCommand
{
    protected function runPackageInstall(string $packageManager): int
    {
        return 0;
    }
}

class InstallCommandTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = sys_get_temp_dir().'/kinetix_install_'.uniqid();
        File::ensureDirectoryExists($this->base.'/resources/js');
        File::put($this->base.'/package.json', json_encode(['private' => true], JSON_PRETTY_PRINT));

        // Redirect base_path() at the helper level so the command writes here.
        $this->app->setBasePath($this->base);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);

        parent::tearDown();
    }

    private function runInstaller(): void
    {
        $command = new TestableInstallCommand;
        $command->setLaravel($this->app);

        (new CommandTester($command))->execute([]);
    }

    private function seedEntryFile(string $ext): void
    {
        File::put($this->base."/resources/js/app.{$ext}", <<<'JS'
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';

createInertiaApp({
  resolve: (name) => name,
  setup({ el, App, props, plugin }) {
    createApp({ render: () => h(App, props) }).use(plugin).mount(el);
  },
});
JS);
    }

    public function test_it_adds_dependencies_and_creates_the_pinia_store(): void
    {
        $this->seedEntryFile('ts');

        $this->runInstaller();

        $package = json_decode(File::get($this->base.'/package.json'), true);
        $this->assertArrayHasKey('pinia', $package['dependencies']);
        $this->assertArrayHasKey('vue-i18n', $package['dependencies']);

        $this->assertTrue(File::exists($this->base.'/resources/js/stores/index.ts'));
        $this->assertStringContainsString('createPinia', File::get($this->base.'/resources/js/stores/index.ts'));
    }

    public function test_it_injects_with_app_with_the_typescript_cast_for_ts_entry(): void
    {
        $this->seedEntryFile('ts');

        $this->runInstaller();

        $app = File::get($this->base.'/resources/js/app.ts');
        $this->assertStringContainsString('withApp(app, { page })', $app);
        $this->assertStringContainsString('app.use(pinia)', $app);
        $this->assertStringContainsString('locale: page.props.locale as string | undefined', $app);
    }

    public function test_it_omits_the_typescript_cast_for_js_entry(): void
    {
        $this->seedEntryFile('js');

        $this->runInstaller();

        $app = File::get($this->base.'/resources/js/app.js');
        $this->assertStringContainsString('withApp(app, { page })', $app);
        $this->assertStringContainsString('locale: page.props.locale,', $app);
        $this->assertStringNotContainsString('as string | undefined', $app);
        $this->assertTrue(File::exists($this->base.'/resources/js/stores/index.js'));
    }

    public function test_it_is_idempotent(): void
    {
        $this->seedEntryFile('ts');

        $this->runInstaller();
        $this->runInstaller();

        // withApp injected exactly once.
        $app = File::get($this->base.'/resources/js/app.ts');
        $this->assertSame(1, substr_count($app, 'withApp(app, { page })'));
    }
}
