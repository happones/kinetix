<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Commands\InstallCommand;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
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

        // Core runtime deps the published components import. vue-virtual and
        // echo-vue are core: Comments/Kanban/MediaLibrary and
        // KinetixNotifications import them statically, so any app compiling
        // the published components needs them at BUILD time.
        foreach (['pinia', 'vue-i18n', 'reka-ui', '@internationalized/date', '@lucide/vue', 'vue-sonner', '@tanstack/vue-virtual', '@laravel/echo-vue'] as $dep) {
            $this->assertArrayHasKey($dep, $deps, "expected {$dep} to be added");
        }

        // Feature-specific deps are NOT added without their flags.
        $this->assertArrayNotHasKey('@unovis/vue', $deps);
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

    public function test_a_setup_entry_is_wired_inside_setup_with_the_typescript_cast(): void
    {
        $this->seedEntryFile('ts');

        $this->runInstaller();

        // Inertia calls setup() INSTEAD of withApp(): a withApp next to it
        // would never run, leaving every page without i18n or Pinia.
        $this->assertSame(<<<'JS'
import { createI18n } from 'vue-i18n';
import pinia from '@/stores';
import Locale from './vue-i18n-locales';
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';

createInertiaApp({
  resolve: (name) => name,
  setup({ el, App, props, plugin }) {
    const i18n = createI18n({
        legacy: false,
        locale: props.initialPage.props.locale as string | undefined,
        messages: Locale,
    });

    createApp({ render: () => h(App, props) }).use(i18n).use(pinia).use(plugin).mount(el);
  },
});
JS, File::get($this->base.'/resources/js/app.ts'));
    }

    public function test_it_omits_the_typescript_cast_for_js_entry(): void
    {
        $this->seedEntryFile('js');

        $this->runInstaller();

        $app = File::get($this->base.'/resources/js/app.js');
        $this->assertStringNotContainsString('withApp', $app);
        $this->assertStringContainsString('locale: props.initialPage.props.locale,', $app);
        $this->assertStringContainsString('.use(i18n).use(pinia).use(plugin)', $app);
        $this->assertStringNotContainsString('as string | undefined', $app);
        $this->assertTrue(File::exists($this->base.'/resources/js/stores/index.js'));
    }

    public function test_it_is_idempotent(): void
    {
        $this->seedEntryFile('ts');

        $this->runInstaller();
        $this->runInstaller();

        // Wired exactly once.
        $app = File::get($this->base.'/resources/js/app.ts');
        $this->assertSame(1, substr_count($app, 'createI18n({'));
        $this->assertSame(1, substr_count($app, '.use(i18n)'));
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

    public function test_the_sonner_stylesheet_import_is_added_after_the_last_import(): void
    {
        $this->seedEntryFile('ts');
        File::ensureDirectoryExists($this->base.'/resources/css');
        File::put($this->base.'/resources/css/app.css', "@import 'tailwindcss';\n\nbody { color: red; }\n");

        $this->runInstaller();

        $css = File::get($this->base.'/resources/css/app.css');

        // Inserted AFTER tailwind (KinetixToaster's variable overrides expect
        // that ordering) but BEFORE any rule — @import must precede rules.
        $this->assertMatchesRegularExpression(
            "/@import 'tailwindcss';\n@import 'vue-sonner\/style\.css';.*\n\nbody/s",
            $css,
        );

        // Idempotent.
        $this->runInstaller();
        $this->assertSame(1, substr_count(File::get($this->base.'/resources/css/app.css'), 'vue-sonner/style.css'));
    }

    public function test_the_sonner_import_never_splits_a_multi_line_import_and_keeps_crlf(): void
    {
        $this->seedEntryFile('ts');
        File::ensureDirectoryExists($this->base.'/resources/css');
        File::put($this->base.'/resources/css/app.css', "@import 'tailwindcss';\r\n@import 'tw-animate-css'\r\n    layer(base);\r\n\r\n:root { --success: red; }\r\n@theme { --color-success: green; }\r\n");

        $this->runInstaller();

        $css = File::get($this->base.'/resources/css/app.css');

        $this->assertStringStartsWith(
            "@import 'tailwindcss';\r\n@import 'tw-animate-css'\r\n    layer(base);\r\n@import 'vue-sonner/style.css';",
            $css,
        );
        $this->assertSame(0, preg_match('/(?<!\r)\n/', $css));
    }

    public function test_the_sonner_import_goes_after_an_import_with_a_trailing_comment(): void
    {
        $this->seedEntryFile('ts');
        File::ensureDirectoryExists($this->base.'/resources/css');
        File::put($this->base.'/resources/css/app.css', "@import 'tailwindcss'; /* base */\n@theme inline {\n    --color-success: green;\n}\n");

        $this->runInstaller();

        $this->assertStringStartsWith(
            "@import 'tailwindcss'; /* base */\n@import 'vue-sonner/style.css';",
            File::get($this->base.'/resources/css/app.css'),
        );
    }

    public function test_an_install_without_a_safe_place_for_the_exclusions_prints_a_hint(): void
    {
        $this->seedEntryFile('ts');
        $this->seedTailwindProject('4.1.11');
        File::put($this->base.'/resources/css/app.css', "@import 'tailwindcss'; .x { --color-success: red; }\n");

        $tester = $this->runInstaller();

        $this->assertStringNotContainsString('@source not', File::get($this->base.'/resources/css/app.css'));
        $this->assertStringContainsString('no safe place', $tester->getDisplay());
    }

    public function test_the_sonner_import_keeps_a_byte_order_mark_first(): void
    {
        $this->seedEntryFile('ts');
        File::ensureDirectoryExists($this->base.'/resources/css');
        File::put($this->base.'/resources/css/app.css', "\xEF\xBB\xBF@import 'tailwindcss';\n@theme { --color-success: green; }\n");

        $this->runInstaller();

        $this->assertStringStartsWith(
            "\xEF\xBB\xBF@import 'tailwindcss';\n@import 'vue-sonner/style.css';",
            File::get($this->base.'/resources/css/app.css'),
        );
    }

    public function test_the_sonner_import_never_goes_above_a_leading_charset(): void
    {
        $this->seedEntryFile('ts');
        File::ensureDirectoryExists($this->base.'/resources/css');
        File::put($this->base.'/resources/css/app.css', "@charset \"UTF-8\";\n@import 'tailwindcss';\n@theme { --color-success: green; }\n");

        $this->runInstaller();

        $this->assertStringStartsWith(
            "@charset \"UTF-8\";\n@import 'tailwindcss';\n@import 'vue-sonner/style.css';",
            File::get($this->base.'/resources/css/app.css'),
        );
    }

    public function test_the_sonner_import_goes_below_a_charset_when_there_is_no_import(): void
    {
        $this->seedEntryFile('ts');
        File::ensureDirectoryExists($this->base.'/resources/css');
        File::put($this->base.'/resources/css/app.css', "\xEF\xBB\xBF@charset \"UTF-8\";\n@theme { --color-success: green; }\n");

        $this->runInstaller();

        $this->assertStringStartsWith(
            "\xEF\xBB\xBF@charset \"UTF-8\";\n@import 'vue-sonner/style.css';",
            File::get($this->base.'/resources/css/app.css'),
        );
    }

    public function test_the_status_tokens_are_added_once_to_a_stylesheet_that_maps_none(): void
    {
        $this->seedEntryFile('ts');
        File::ensureDirectoryExists($this->base.'/resources/css');
        File::put($this->base.'/resources/css/app.css', "@import 'tailwindcss';\n\n:root { --primary: red; }\n");

        $this->runInstaller();
        $this->runInstaller();

        $css = File::get($this->base.'/resources/css/app.css');

        $this->assertSame(1, substr_count($css, '--color-success: hsl(var(--success));'));
        $this->assertStringContainsString('--info-foreground: 204 80% 16%;', $css);
        $this->assertStringContainsString(':root { --primary: red; }', $css);
    }

    public function test_a_stylesheet_that_already_maps_its_status_colors_is_left_alone(): void
    {
        $this->seedEntryFile('ts');
        File::ensureDirectoryExists($this->base.'/resources/css');
        File::put($this->base.'/resources/css/app.css', "@import 'tailwindcss';\n@theme { --color-success: green; }\n");

        $this->runInstaller();

        $this->assertStringNotContainsString('hsl(var(--success))', File::get($this->base.'/resources/css/app.css'));
    }

    public function test_a_stylesheet_with_no_imports_gets_the_import_at_the_top(): void
    {
        $this->seedEntryFile('ts');
        File::ensureDirectoryExists($this->base.'/resources/css');
        File::put($this->base.'/resources/css/app.css', "body { color: red; }\n");

        $this->runInstaller();

        $this->assertStringStartsWith(
            "@import 'vue-sonner/style.css';",
            File::get($this->base.'/resources/css/app.css'),
        );
    }

    public function test_the_agent_skills_are_kept_out_of_the_tailwind_scan(): void
    {
        $this->seedEntryFile('ts');
        $this->seedTailwindProject('4.1.11');

        $tester = $this->runInstaller();

        $this->assertStringContainsString("@source not '../../.claude/skills';", File::get($this->base.'/resources/css/app.css'));
        $this->assertStringContainsString("Excluded the agent skills from Tailwind's scan", $tester->getDisplay());
    }

    public function test_an_older_tailwind_gets_a_hint_instead_of_the_exclusion(): void
    {
        $this->seedEntryFile('ts');
        $this->seedTailwindProject('4.0.17');

        $tester = $this->runInstaller();

        $this->assertStringNotContainsString('@source not', File::get($this->base.'/resources/css/app.css'));
        $this->assertStringContainsString("Skipped excluding the agent skills from Tailwind's scan", $tester->getDisplay());
        $this->assertStringContainsString("@source not '../../.claude/skills';", $tester->getDisplay());
    }

    private function seedTailwindProject(string $version): void
    {
        File::ensureDirectoryExists($this->base.'/resources/css');
        File::put($this->base.'/resources/css/app.css', "@import 'tailwindcss';\n");
        File::ensureDirectoryExists($this->base.'/node_modules/tailwindcss');
        File::put($this->base.'/node_modules/tailwindcss/package.json', json_encode(['version' => $version]));
        File::ensureDirectoryExists($this->base.'/.claude/skills/kinetix-tables');
    }

    public function test_a_missing_stylesheet_does_not_break_the_install(): void
    {
        $this->seedEntryFile('ts');

        $tester = $this->runInstaller();

        $this->assertStringContainsString('add `@import', $tester->getDisplay());
        $this->assertFalse(File::exists($this->base.'/resources/css/app.css'));
    }

    public function test_provider_is_scaffolded_and_registered_by_default(): void
    {
        $this->seedEntryFile('ts');
        $this->seedBootstrapProviders();

        $this->runInstaller();

        $providerPath = $this->base.'/app/Providers/KinetixServiceProvider.php';
        $this->assertTrue(File::exists($providerPath));

        $provider = File::get($providerPath);
        $this->assertStringContainsString('namespace App\\Providers;', $provider);
        $this->assertStringContainsString('class KinetixServiceProvider extends ServiceProvider', $provider);
        $this->assertStringContainsString('registerPermissions', $provider);
        $this->assertStringContainsString('registerModules', $provider);

        $bootstrap = File::get($this->base.'/bootstrap/providers.php');
        $this->assertStringContainsString('App\\Providers\\KinetixServiceProvider::class', $bootstrap);
    }

    public function test_skip_provider_flag_opts_out_of_the_scaffold(): void
    {
        $this->seedEntryFile('ts');
        $this->seedBootstrapProviders();

        $this->runInstaller(['--skip-provider' => true]);

        $this->assertFalse(File::exists($this->base.'/app/Providers/KinetixServiceProvider.php'));
        $this->assertStringNotContainsString(
            'KinetixServiceProvider',
            File::get($this->base.'/bootstrap/providers.php'),
        );
    }

    public function test_an_existing_provider_file_is_never_overwritten(): void
    {
        $this->seedEntryFile('ts');
        $this->seedBootstrapProviders();

        $providerPath = $this->base.'/app/Providers/KinetixServiceProvider.php';
        File::ensureDirectoryExists(dirname($providerPath));
        File::put($providerPath, '<?php // customized by the host');

        $this->runInstaller();

        $this->assertSame('<?php // customized by the host', File::get($providerPath));
    }

    public function test_provider_scaffold_is_idempotent(): void
    {
        $this->seedEntryFile('ts');
        $this->seedBootstrapProviders();

        $this->runInstaller();
        $this->runInstaller();

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

    /**
     * The starter kit's entry file: a multi-line resolver with a TypeScript
     * generic, a template literal and a nested glob array.
     */
    private function seedStarterKitEntry(): string
    {
        $entry = <<<'JS'
import '../css/app.css';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { initializeTheme } from './composables/useAppearance';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});

initializeTheme();
JS;

        File::put($this->base.'/resources/js/app.ts', $entry);

        return $entry;
    }

    public function test_lazy_locales_wires_a_setup_entry_inside_setup(): void
    {
        $this->seedStarterKitEntry();

        $tester = $this->runInstaller(['--lazy-locales' => true]);

        $this->assertSame(<<<'JS'
import { createI18n } from 'vue-i18n';
import { attachKinetixI18n, kinetixLoadedMessages, kinetixLocaleLoaderFromGlob, kinetixPageLocale, registerKinetixLocales, withKinetixLocales } from '@/plugins/kinetixLocales';
import pinia from '@/stores';
import '../css/app.css';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { initializeTheme } from './composables/useAppearance';

registerKinetixLocales({
    loader: kinetixLocaleLoaderFromGlob(import.meta.glob(['./locales/*.ts', '!./locales/index.ts'])),
    fallbackLocale: 'en',
});

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: withKinetixLocales((name) =>
        resolvePageComponent(
            `./pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./pages/**/*.vue'),
        )),
    setup({ el, App, props, plugin }) {
        const i18n = createI18n({
            legacy: false,
            locale: kinetixPageLocale(props.initialPage),
            fallbackLocale: 'en',
            messages: kinetixLoadedMessages(),
        });

        attachKinetixI18n(i18n);

        createApp({ render: () => h(App, props) }).use(i18n).use(pinia)
            .use(plugin)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});

initializeTheme();
JS, File::get($this->base.'/resources/js/app.ts'));

        $output = $tester->getDisplay();
        $this->assertStringContainsString('composer require happones/laravel-vue-i18n-generator', $output);
        $this->assertStringContainsString('php artisan vue-i18n:generate --multi-locales', $output);
        $this->assertStringContainsString("'vue_i18n_options' => ['--multi-locales' => true]", $output);
        $this->assertStringContainsString('ssr.ts', $output);
        $this->assertStringContainsString('public.ts', $output);
    }

    public function test_lazy_locales_follows_the_generator_path_and_the_fallback_locale(): void
    {
        $this->seedStarterKitEntry();
        config([
            'vue-i18n-generator.jsPath' => '/resources/js/i18n/generated',
            'app.fallback_locale'       => 'es',
        ]);

        $this->runInstaller(['--lazy-locales' => true]);

        $app = File::get($this->base.'/resources/js/app.ts');
        $this->assertStringContainsString("import.meta.glob(['./i18n/generated/*.ts', '!./i18n/generated/index.ts'])", $app);
        // Raw Laravel JSON catalogs are never globbed: they skip the
        // generator's `:name` / plural / `@` conversion.
        $this->assertStringNotContainsString('lang/*.json', $app);
        $this->assertSame(2, substr_count($app, "fallbackLocale: 'es',"));
    }

    public function test_an_unparseable_resolve_falls_back_to_the_eager_wiring_and_prints_the_snippet(): void
    {
        File::put($this->base.'/resources/js/app.ts', <<<'JS'
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';

createInertiaApp({
  resolve(name) { return name; },
  setup({ el, App, props, plugin }) {
    createApp({ render: () => h(App, props) }).use(plugin).mount(el);
  },
});
JS);

        $tester = $this->runInstaller(['--lazy-locales' => true]);
        $app    = File::get($this->base.'/resources/js/app.ts');

        // The eager wiring, never half of the lazy one.
        $this->assertStringContainsString("import Locale from './vue-i18n-locales';", $app);
        $this->assertStringContainsString('messages: Locale,', $app);
        $this->assertStringNotContainsString('kinetixLocales', $app);
        $this->assertStringNotContainsString('withKinetixLocales', $app);

        // In setup(), where Inertia runs it — never a withApp next to setup.
        $this->assertStringNotContainsString('withApp', $app);
        $this->assertStringContainsString('.use(i18n).use(pinia).use(plugin)', $app);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Could not find a single `resolve:`', $output);
        $this->assertStringContainsString('withKinetixLocales(/* your resolve */)', $output);
    }

    public function test_an_already_wired_entry_is_left_unchanged_and_gets_the_snippet(): void
    {
        $this->seedEntryFile('ts');
        $this->runInstaller();
        $wired = File::get($this->base.'/resources/js/app.ts');

        $tester = $this->runInstaller(['--lazy-locales' => true]);

        $this->assertSame($wired, File::get($this->base.'/resources/js/app.ts'));
        $this->assertStringContainsString('already registers vue-i18n', $tester->getDisplay());
        $this->assertStringContainsString('registerKinetixLocales({', $tester->getDisplay());
        $this->assertStringNotContainsString('lang/*.json', $tester->getDisplay());
    }

    public function test_without_the_flag_the_entry_gets_the_eager_wiring(): void
    {
        $this->seedStarterKitEntry();

        $tester = $this->runInstaller();
        $app    = File::get($this->base.'/resources/js/app.ts');

        $this->assertStringContainsString("import Locale from './vue-i18n-locales';", $app);
        $this->assertStringContainsString('resolve: (name) =>', $app);
        $this->assertStringNotContainsString('kinetixLocales', $app);
        $this->assertStringNotContainsString('On-demand locales', $tester->getDisplay());
    }

    /**
     * An entry with neither setup() nor withApp() (the Inertia 3 default).
     */
    private function seedPlainEntry(bool $withResolve = true): void
    {
        $resolve = $withResolve ? "    resolve: (name) => name,\n" : '';

        File::put($this->base.'/resources/js/app.ts', "import { createInertiaApp } from '@inertiajs/vue3';\n\ncreateInertiaApp({\n{$resolve}    progress: {\n        color: '#4B5563',\n    },\n});\n");
    }

    private function seedWithAppEntry(): void
    {
        File::put($this->base.'/resources/js/app.ts', <<<'JS'
import { createInertiaApp } from '@inertiajs/vue3';
import { vFocus } from '@/lib/focus';

createInertiaApp({
    resolve: (name) => name,
    withApp(app, { page }) {
        app.directive('focus', vFocus);
    },
});

JS);
    }

    public function test_an_entry_without_setup_or_with_app_gets_a_with_app_property(): void
    {
        $this->seedPlainEntry();

        $this->runInstaller();

        $this->assertSame(<<<'JS'
import { createI18n } from 'vue-i18n';
import pinia from '@/stores';
import Locale from './vue-i18n-locales';
import { createInertiaApp } from '@inertiajs/vue3';

createInertiaApp({
    resolve: (name) => name,
    progress: {
        color: '#4B5563',
    },
    withApp(app, { page }) {
        const i18n = createI18n({
            legacy: false,
            locale: page.props.locale as string | undefined,
            messages: Locale,
        });

        app.use(i18n);
        app.use(pinia);
    },
});

JS, File::get($this->base.'/resources/js/app.ts'));
    }

    public function test_lazy_locales_add_a_with_app_property_to_an_entry_without_setup_or_with_app(): void
    {
        $this->seedPlainEntry();

        $this->runInstaller(['--lazy-locales' => true]);

        $this->assertSame(<<<'JS'
import { createI18n } from 'vue-i18n';
import { attachKinetixI18n, kinetixLoadedMessages, kinetixLocaleLoaderFromGlob, kinetixPageLocale, registerKinetixLocales, withKinetixLocales } from '@/plugins/kinetixLocales';
import pinia from '@/stores';
import { createInertiaApp } from '@inertiajs/vue3';

registerKinetixLocales({
    loader: kinetixLocaleLoaderFromGlob(import.meta.glob(['./locales/*.ts', '!./locales/index.ts'])),
    fallbackLocale: 'en',
});

createInertiaApp({
    resolve: withKinetixLocales((name) => name),
    progress: {
        color: '#4B5563',
    },
    withApp(app, { page }) {
        const i18n = createI18n({
            legacy: false,
            locale: kinetixPageLocale(page),
            fallbackLocale: 'en',
            messages: kinetixLoadedMessages(),
        });

        attachKinetixI18n(i18n);
        app.use(i18n);
        app.use(pinia);
    },
});

JS, File::get($this->base.'/resources/js/app.ts'));
    }

    public function test_an_existing_with_app_is_wired_inside(): void
    {
        $this->seedWithAppEntry();

        $this->runInstaller();

        $this->assertSame(<<<'JS'
import { createI18n } from 'vue-i18n';
import pinia from '@/stores';
import Locale from './vue-i18n-locales';
import { createInertiaApp } from '@inertiajs/vue3';
import { vFocus } from '@/lib/focus';

createInertiaApp({
    resolve: (name) => name,
    withApp(app, { page }) {
        const i18n = createI18n({
            legacy: false,
            locale: page.props.locale as string | undefined,
            messages: Locale,
        });

        app.use(i18n);
        app.use(pinia);

        app.directive('focus', vFocus);
    },
});

JS, File::get($this->base.'/resources/js/app.ts'));
    }

    public function test_lazy_locales_wire_an_existing_with_app_inside(): void
    {
        $this->seedWithAppEntry();

        $this->runInstaller(['--lazy-locales' => true]);

        $this->assertSame(<<<'JS'
import { createI18n } from 'vue-i18n';
import { attachKinetixI18n, kinetixLoadedMessages, kinetixLocaleLoaderFromGlob, kinetixPageLocale, registerKinetixLocales, withKinetixLocales } from '@/plugins/kinetixLocales';
import pinia from '@/stores';
import { createInertiaApp } from '@inertiajs/vue3';
import { vFocus } from '@/lib/focus';

registerKinetixLocales({
    loader: kinetixLocaleLoaderFromGlob(import.meta.glob(['./locales/*.ts', '!./locales/index.ts'])),
    fallbackLocale: 'en',
});

createInertiaApp({
    resolve: withKinetixLocales((name) => name),
    withApp(app, { page }) {
        const i18n = createI18n({
            legacy: false,
            locale: kinetixPageLocale(page),
            fallbackLocale: 'en',
            messages: kinetixLoadedMessages(),
        });

        attachKinetixI18n(i18n);
        app.use(i18n);
        app.use(pinia);

        app.directive('focus', vFocus);
    },
});

JS, File::get($this->base.'/resources/js/app.ts'));
    }

    public function test_a_with_app_whose_parameters_cannot_be_read_is_refused(): void
    {
        $entry = "import { createInertiaApp } from '@inertiajs/vue3';\n\ncreateInertiaApp({\n    resolve: (name) => name,\n    withApp(app, { page: current }) {\n        app.mount();\n    },\n});\n";

        foreach ([[], ['--lazy-locales' => true]] as $options) {
            File::put($this->base.'/resources/js/app.ts', $entry);

            $tester = $this->runInstaller($options);

            $this->assertSame($entry, File::get($this->base.'/resources/js/app.ts'));
            $this->assertStringContainsString('left as it is', $tester->getDisplay());
            $this->assertStringContainsString('withApp(app, { page })', $tester->getDisplay());
        }
    }

    public function test_an_entry_with_both_setup_and_with_app_is_refused(): void
    {
        $entry = "import { createApp, h } from 'vue';\nimport { createInertiaApp } from '@inertiajs/vue3';\n\ncreateInertiaApp({\n    resolve: (name) => name,\n    withApp(app) {},\n    setup({ el, App, props, plugin }) {\n        createApp({ render: () => h(App, props) }).use(plugin).mount(el);\n    },\n});\n";

        foreach ([[], ['--lazy-locales' => true]] as $options) {
            File::put($this->base.'/resources/js/app.ts', $entry);

            $this->runInstaller($options);

            $this->assertSame($entry, File::get($this->base.'/resources/js/app.ts'));
        }
    }

    public function test_a_setup_without_a_single_create_app_is_refused(): void
    {
        $entry = "import { createInertiaApp } from '@inertiajs/vue3';\n\ncreateInertiaApp({\n    resolve: (name) => name,\n    setup({ el, App, props, plugin }) {\n        mountTheApp(el, App, props, plugin);\n    },\n});\n";

        foreach ([[], ['--lazy-locales' => true]] as $options) {
            File::put($this->base.'/resources/js/app.ts', $entry);

            $this->runInstaller($options);

            $this->assertSame($entry, File::get($this->base.'/resources/js/app.ts'));
        }
    }

    public function test_a_comment_or_string_mentioning_setup_is_never_spliced_into(): void
    {
        File::put($this->base.'/resources/js/app.ts', <<<'JS'
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
// Error tracking: Sentry.setup() runs before the app.
const hint = 'withApp( is not here';
createInertiaApp({
  resolve: (name) => name,
  setup({ el, App, props, plugin }) {
    createApp({ render: () => h(App, props) }).use(plugin).mount(el);
  },
});
JS);

        $this->runInstaller(['--lazy-locales' => true]);

        $app = File::get($this->base.'/resources/js/app.ts');

        $this->assertStringContainsString("// Error tracking: Sentry.setup() runs before the app.\nconst hint = 'withApp( is not here';\ncreateInertiaApp({", $app);
        $this->assertStringContainsString('locale: kinetixPageLocale(props.initialPage),', $app);
        $this->assertStringContainsString('createApp({ render: () => h(App, props) }).use(i18n).use(pinia).use(plugin).mount(el);', $app);
        $this->assertStringNotContainsString('withApp(app', $app);
    }

    public function test_the_inertia_three_starter_kit_without_resolve_gets_the_eager_wiring_in_lazy_mode(): void
    {
        // laravel/vue-starter-kit on Inertia 3: no resolve (the Vite plugin
        // resolves pages), no setup, no withApp.
        $this->seedPlainEntry(withResolve: false);

        $tester = $this->runInstaller(['--lazy-locales' => true]);
        $app    = File::get($this->base.'/resources/js/app.ts');

        $this->assertStringContainsString("    withApp(app, { page }) {\n        const i18n = createI18n({", $app);
        $this->assertStringContainsString('messages: Locale,', $app);
        $this->assertStringNotContainsString('kinetixLocales', $app);
        $this->assertStringContainsString('Could not find a single `resolve:`', $tester->getDisplay());
        $this->assertStringContainsString('Without a resolve:', $tester->getDisplay());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unreadableAppObjects(): array
    {
        return [
            'async setup method' => ["import { createApp, h } from 'vue';\nimport { createInertiaApp } from '@inertiajs/vue3';\n\ncreateInertiaApp({\n    resolve: (name) => name,\n    async setup({ el, App, props, plugin }) {\n        createApp({ render: () => h(App, props) }).use(plugin).mount(el);\n    },\n});\n"],
            'spread options'     => ["import { createInertiaApp } from '@inertiajs/vue3';\nimport base from './inertia-base';\n\ncreateInertiaApp({\n    ...base,\n    resolve: (name) => name,\n});\n"],
            'async arrow setup'  => ["import { createApp, h } from 'vue';\nimport { createInertiaApp } from '@inertiajs/vue3';\n\ncreateInertiaApp({\n    resolve: (name) => name,\n    setup: async ({ el, App, props, plugin }) => {\n        createApp({ render: () => h(App, props) }).use(plugin).mount(el);\n    },\n});\n"],
            'setup shorthand'    => ["import { createInertiaApp } from '@inertiajs/vue3';\nimport { setup } from './boot';\n\ncreateInertiaApp({\n    resolve: (name) => name,\n    setup,\n});\n"],
        ];
    }

    /**
     * A property the scanner can't fully read may be (or hold) a `setup`,
     * and Inertia runs `setup` INSTEAD of `withApp`: adding one would leave
     * every page without i18n or Pinia. The file is left as written.
     */
    #[DataProvider('unreadableAppObjects')]
    public function test_an_app_object_it_cannot_fully_read_is_left_alone_in_both_modes(string $entry): void
    {
        foreach ([[], ['--lazy-locales' => true]] as $options) {
            File::put($this->base.'/resources/js/app.ts', $entry);

            $tester = $this->runInstaller($options);

            $this->assertSame($entry, File::get($this->base.'/resources/js/app.ts'));
            $this->assertStringContainsString('left as it is', $tester->getDisplay());
            $this->assertStringContainsString('withApp(app, { page })', $tester->getDisplay());
        }
    }

    public function test_a_commented_out_pinia_use_still_gets_pinia(): void
    {
        File::put($this->base.'/resources/js/app.ts', "import { createInertiaApp } from '@inertiajs/vue3';\n\n// TODO: app.use(pinia) once the store exists\ncreateInertiaApp({\n    resolve: (name) => name,\n});\n");

        $this->runInstaller();

        $this->assertStringContainsString("        app.use(i18n);\n        app.use(pinia);\n    },", File::get($this->base.'/resources/js/app.ts'));
    }
}
