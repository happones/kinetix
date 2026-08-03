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

    private string $originalBase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalBase = base_path();
        $this->base         = sys_get_temp_dir().'/kinetix_install_'.uniqid();
        File::ensureDirectoryExists($this->base.'/resources/js');
        File::put($this->base.'/package.json', json_encode(['private' => true], JSON_PRETTY_PRINT));

        // Redirect base_path() at the helper level so the command writes here.
        $this->app->setBasePath($this->base);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);

        // The skills publish map resolves against the boot-time base path (the
        // shared testbench skeleton), so clean that up too.
        File::deleteDirectory($this->originalBase.'/.claude');

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runInstaller(array $options = []): CommandTester
    {
        $command = new TestableInstallCommand;
        $command->setLaravel($this->app);

        $tester = new CommandTester($command);
        $tester->execute($options);

        return $tester;
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

        $deps = json_decode(File::get($this->base.'/package.json'), true)['dependencies'];

        // Core runtime deps the published components import. vue-virtual is
        // core: Comments/Kanban/MediaLibrary import it statically, so any app
        // compiling the published components needs it at BUILD time.
        foreach (['pinia', 'vue-i18n', 'reka-ui', '@internationalized/date', '@lucide/vue', 'vue-sonner', '@tanstack/vue-virtual'] as $dep) {
            $this->assertArrayHasKey($dep, $deps, "expected {$dep} to be added");
        }

        // Feature-specific deps are NOT added without their flags.
        $this->assertArrayNotHasKey('@unovis/vue', $deps);
        $this->assertArrayNotHasKey('@laravel/echo-vue', $deps);
        $this->assertArrayNotHasKey('@tanstack/vue-table', $deps);

        $this->assertTrue(File::exists($this->base.'/resources/js/stores/index.ts'));
        $this->assertStringContainsString('createPinia', File::get($this->base.'/resources/js/stores/index.ts'));
    }

    public function test_optional_dependencies_are_added_with_flags(): void
    {
        $this->seedEntryFile('ts');

        $this->runInstaller([
            '--charts'       => true,
            '--tanstack'     => true,
            '--broadcasting' => true,
        ]);

        $deps = json_decode(File::get($this->base.'/package.json'), true)['dependencies'];
        $this->assertArrayHasKey('@unovis/vue', $deps);
        $this->assertArrayHasKey('@unovis/ts', $deps);
        $this->assertArrayHasKey('@tanstack/vue-table', $deps);
        $this->assertArrayHasKey('@tanstack/vue-virtual', $deps);
        $this->assertArrayHasKey('@laravel/echo-vue', $deps);
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

    public function test_it_protects_published_paths_from_prettier(): void
    {
        $this->seedEntryFile('ts');
        $this->runInstaller();

        $ignore = File::get($this->base.'/.prettierignore');

        // The starter kit's `prettier --write resources/` would otherwise
        // reformat the vendor-managed publishes and drift on every upgrade.
        $this->assertStringContainsString('resources/js/components/kinetix/', $ignore);
        $this->assertStringContainsString('resources/js/composables/useKinetix*.ts', $ignore);
        $this->assertStringContainsString('resources/js/composables/kinetix*.ts', $ignore);
        $this->assertStringContainsString('resources/js/stores/kinetix*.ts', $ignore);
        $this->assertStringContainsString('resources/js/plugins/kinetix*.ts', $ignore);
        $this->assertStringContainsString('resources/js/icons/kinetixBrands*', $ignore);
        $this->assertStringContainsString('resources/js/types/kinetix.ts', $ignore);
        $this->assertStringContainsString('resources/js/vue-i18n-locales*', $ignore);
        $this->assertStringContainsString('.claude/skills/kinetix-*/', $ignore);
    }

    public function test_it_gitignores_the_regenerated_output(): void
    {
        // Compiled output that `kinetix:upgrade` rewrites on every composer
        // install — versioning it means a diff on every branch that touches a
        // translation.
        File::put($this->base.'/.gitignore', "/vendor\n/node_modules\n");
        $this->seedEntryFile('ts');
        $this->runInstaller();

        $gitignore = File::get($this->base.'/.gitignore');

        $this->assertStringContainsString('/vendor', $gitignore);
        $this->assertStringContainsString('resources/js/vue-i18n-locales.generated.*', $gitignore);
        $this->assertStringContainsString('resources/js/types/kinetix.ts', $gitignore);
    }

    public function test_gitignore_additions_are_idempotent(): void
    {
        File::put($this->base.'/.gitignore', "/vendor\n");
        $this->seedEntryFile('ts');

        $this->runInstaller();
        $first = File::get($this->base.'/.gitignore');

        $this->runInstaller();
        $this->assertSame($first, File::get($this->base.'/.gitignore'));
    }

    public function test_no_gitignore_is_created_when_the_project_has_none(): void
    {
        $this->seedEntryFile('ts');
        $this->runInstaller();

        $this->assertFileDoesNotExist($this->base.'/.gitignore');
    }

    public function test_it_publishes_the_agent_skills_by_default(): void
    {
        $this->seedEntryFile('ts');

        // Skills only reach an agent from the project itself, never from vendor/,
        // so the installer publishes them without being asked. (Where the files
        // land is covered by SkillsPublishTest — the publish map is resolved when
        // the provider boots, before this test rebases base_path().)
        $this->assertStringContainsString(
            'Published the Kinetix agent skills',
            $this->runInstaller()->getDisplay(),
        );
    }

    public function test_skip_skills_opts_out(): void
    {
        $this->seedEntryFile('ts');

        $this->assertStringNotContainsString(
            'Published the Kinetix agent skills',
            $this->runInstaller(['--skip-skills' => true])->getDisplay(),
        );
    }

    public function test_prettierignore_additions_preserve_existing_entries_and_are_idempotent(): void
    {
        File::put($this->base.'/.prettierignore', "dist/\nresources/js/types/index.ts\n");
        $this->seedEntryFile('ts');

        $this->runInstaller();
        $first = File::get($this->base.'/.prettierignore');

        // Host entries kept; the already-present path is not duplicated.
        $this->assertStringContainsString("dist/\n", $first);
        $this->assertSame(1, substr_count($first, 'resources/js/types/index.ts'));

        // Re-running the installer changes nothing.
        $this->runInstaller();
        $this->assertSame($first, File::get($this->base.'/.prettierignore'));
    }

    public function test_provider_is_not_scaffolded_without_the_flag(): void
    {
        $this->seedEntryFile('ts');

        $this->runInstaller();

        $this->assertFalse(File::exists($this->base.'/app/Providers/KinetixServiceProvider.php'));
    }

    public function test_provider_flag_scaffolds_and_registers_the_provider(): void
    {
        $this->seedEntryFile('ts');
        $this->seedBootstrapProviders();

        $this->runInstaller(['--provider' => true]);

        $providerPath = $this->base.'/app/Providers/KinetixServiceProvider.php';
        $this->assertTrue(File::exists($providerPath));

        $provider = File::get($providerPath);
        $this->assertStringContainsString('namespace App\\Providers;', $provider);
        $this->assertStringContainsString('class KinetixServiceProvider extends ServiceProvider', $provider);

        $bootstrap = File::get($this->base.'/bootstrap/providers.php');
        $this->assertStringContainsString('App\\Providers\\KinetixServiceProvider::class', $bootstrap);
    }

    public function test_provider_scaffold_is_idempotent(): void
    {
        $this->seedEntryFile('ts');
        $this->seedBootstrapProviders();

        $this->runInstaller(['--provider' => true]);
        $this->runInstaller(['--provider' => true]);

        $bootstrap = File::get($this->base.'/bootstrap/providers.php');
        $this->assertSame(1, substr_count($bootstrap, 'App\\Providers\\KinetixServiceProvider::class'));
    }

    private function seedBootstrapProviders(): void
    {
        File::ensureDirectoryExists($this->base.'/bootstrap');
        File::put($this->base.'/bootstrap/providers.php', <<<'PHP'
<?php

return [
    App\Providers\AppServiceProvider::class,
];
PHP);
    }
}
