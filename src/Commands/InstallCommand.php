<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Happones\Kinetix\Support\ComposerHook;
use Happones\Kinetix\Support\PublishedFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;

class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kinetix:install
        {--charts : Also install chart/widget dependencies (@unovis/vue, @unovis/ts)}
        {--tanstack : Also install the client-side table dep (@tanstack/vue-table)}
        {--broadcasting : Deprecated no-op — @laravel/echo-vue is now always installed}
        {--tours : Also install the product-tour renderer (driver.js)}
        {--provider : Deprecated no-op — the provider is now scaffolded by default}
        {--skip-provider : Do not scaffold App\Providers\KinetixServiceProvider}
        {--skip-skills : Do not publish the bundled agent skills}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install and configure Kinetix frontend dependencies (Pinia, Vue i18n)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting Kinetix installation...');

        // 1. Package JSON Check & Dependency Addition
        $packageJsonPath = base_path('package.json');
        if (! File::exists($packageJsonPath)) {
            $this->error('package.json not found in the root of the project.');

            return self::FAILURE;
        }

        $packageJson = json_decode(File::get($packageJsonPath), true);
        if (! is_array($packageJson)) {
            $this->error('Failed to parse package.json.');

            return self::FAILURE;
        }

        // Front-end runtime dependencies the published Kinetix components import.
        // (vue and @inertiajs/vue3 are assumed present from the starter kit.)
        if (! isset($packageJson['dependencies'])) {
            $packageJson['dependencies'] = [];
        }

        $dependencies = [
            'pinia'                   => '^2.3.1',
            'vue-i18n'                => '^11.0.0',
            'reka-ui'                 => '^2.0.0',
            '@internationalized/date' => '^3.0.0',
            '@lucide/vue'             => '^1.0.0',
            'vue-sonner'              => '^2.0.0',
            // Long-list virtualization (Comments / Kanban / Media Library).
            // Those components import it statically, so any app compiling the
            // published components needs it at build time — a required peer.
            '@tanstack/vue-virtual' => '^3.0.0',
            // KinetixNotifications.vue imports it statically, so it's required
            // at BUILD time even when broadcasting is never configured —
            // without it the host's Vite build fails on the published
            // component. Runtime stays inert until Echo is configured.
            '@laravel/echo-vue' => '^2.3.0',
        ];

        // Opt-in, feature-specific dependencies.
        if ($this->option('charts')) {
            $dependencies['@unovis/vue'] = '^1.3.0';
            $dependencies['@unovis/ts']  = '^1.3.0';
        }

        // Client-side (TanStack) tables — only apps that opt into
        // `->clientSide()` need it.
        if ($this->option('tanstack')) {
            $dependencies['@tanstack/vue-table'] = '^8.0.0';
        }

        // Kept for backwards compatibility: `@laravel/echo-vue` moved into the
        // base dependency list above (the notifications component needs it at
        // build time regardless), so the flag adds nothing extra today.
        if ($this->option('broadcasting')) {
            $dependencies['@laravel/echo-vue'] = '^2.3.0';
        }

        // Product tours renderer (<KinetixTours /> lazy-imports it).
        if ($this->option('tours')) {
            $dependencies['driver.js'] = '^1.3.0';
        }

        $added = [];
        foreach ($dependencies as $name => $version) {
            if (! isset($packageJson['dependencies'][$name])) {
                $packageJson['dependencies'][$name] = $version;
                $added[]                            = $name;
            }
        }

        if ($added !== []) {
            File::put(
                $packageJsonPath,
                json_encode($packageJson, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL
            );
            $this->info('Added to package.json dependencies: '.implode(', ', $added).'.');
        }

        // Determine package manager
        $packageManager = 'npm';
        if (File::exists(base_path('pnpm-lock.yaml'))) {
            $packageManager = 'pnpm';
        } elseif (File::exists(base_path('yarn.lock'))) {
            $packageManager = 'yarn';
        }

        $this->info("Installing npm dependencies using {$packageManager}...");
        $exitCode = $this->runPackageInstall($packageManager);

        if ($exitCode !== 0) {
            $this->warn("Failed to run '{$packageManager} install'. Please run it manually later.");
        } else {
            $this->info('Dependencies installed successfully.');
        }

        $this->publishAgentSkills();

        // 2. Find and update main Inertia entry file (app.ts / app.js)
        $appPaths = [
            base_path('resources/js/app.ts'),
            base_path('resources/js/app.js'),
        ];
        $appFile = null;
        foreach ($appPaths as $path) {
            if (File::exists($path)) {
                $appFile = $path;
                break;
            }
        }

        if (! $appFile) {
            $this->warn('Could not find resources/js/app.ts or resources/js/app.js. Please configure your main entry file manually.');

            return self::SUCCESS;
        }

        $ext = pathinfo($appFile, PATHINFO_EXTENSION);
        $this->info("Found main entry file: resources/js/app.{$ext}");

        // Create resources/js/stores/index.ts (or js) if it doesn't exist
        $storesPath = base_path('resources/js/stores');
        if (! File::isDirectory($storesPath)) {
            File::makeDirectory($storesPath, 0755, true);
        }

        $storesIndexFile = $storesPath.'/index.'.$ext;
        if (! File::exists($storesIndexFile)) {
            $storesContent = <<<'JS'
import { createPinia } from 'pinia';

const pinia = createPinia();

export default pinia;
JS;
            File::put($storesIndexFile, $storesContent.PHP_EOL);
            $this->info("Created resources/js/stores/index.{$ext}");
        }

        // Update app.ts / app.js
        $content    = File::get($appFile);
        $updatedApp = false;

        // Check/Add imports
        $importsToAdd = [];
        if (! str_contains($content, 'vue-i18n')) {
            $importsToAdd[] = "import { createI18n } from 'vue-i18n';";
        }
        if (! str_contains($content, '@/stores') && ! str_contains($content, './stores')) {
            $importsToAdd[] = "import pinia from '@/stores';";
        }
        if (! str_contains($content, 'vue-i18n-locales')) {
            $importsToAdd[] = "import Locale from './vue-i18n-locales';";
        }

        if (! empty($importsToAdd)) {
            $lines    = explode("\n", $content);
            $inserted = false;
            for ($i = 0; $i < count($lines); $i++) {
                if (str_starts_with(trim($lines[$i]), 'import ')) {
                    array_splice($lines, $i, 0, $importsToAdd);
                    $inserted = true;
                    break;
                }
            }
            if (! $inserted) {
                array_unshift($lines, ...$importsToAdd);
            }
            $content    = implode("\n", $lines);
            $updatedApp = true;
        }

        // Insert withApp inside createInertiaApp
        if (! str_contains($content, 'withApp(') && ! str_contains($content, 'withApp (')) {
            $setupPos = strpos($content, 'setup(');
            if ($setupPos === false) {
                $setupPos = strpos($content, 'setup (');
            }

            if ($setupPos !== false) {
                // Only emit the TypeScript cast when the entry file is .ts —
                // injecting `as string | undefined` into a .js file is a syntax error.
                $localeLine = $ext === 'ts'
                    ? 'locale: page.props.locale as string | undefined,'
                    : 'locale: page.props.locale,';

                $withAppCode = <<<JS
    withApp(app, { page }) {
        const i18n = createI18n({
            legacy: false,
            {$localeLine}
            messages: Locale,
        });

        app.use(i18n);
        app.use(pinia);
    },
JS;
                $content    = substr_replace($content, $withAppCode."\n", $setupPos, 0);
                $updatedApp = true;
            } else {
                $this->warn('Could not locate the setup() method in your app file to inject withApp configuration. Please register vue-i18n and pinia manually.');
            }
        }

        if ($updatedApp) {
            File::put($appFile, $content);
            $this->info("Updated resources/js/app.{$ext} with i18n and Pinia registration.");
        } else {
            $this->info("resources/js/app.{$ext} is already configured.");
        }

        $this->registerUpgradeHook();
        $this->ensureSonnerStylesheet();
        $this->protectPublishedPathsFromFormatters();
        $this->gitignoreGeneratedOutput();

        if (! $this->option('skip-provider')) {
            $this->scaffoldProvider();
        }

        // Baseline the just-published files so `kinetix:upgrade` can tell
        // local edits apart from upstream changes.
        PublishedFiles::record();

        $this->info('Kinetix installation and configuration completed successfully!');

        return self::SUCCESS;
    }

    /**
     * Copy the per-module agent skills into the project's skills directory.
     *
     * Agents only load skills from the project itself — a skill sitting in
     * `vendor/happones/kinetix/resources/boost/skills` is invisible, which is
     * why this runs by default instead of being an opt-in tag nobody discovers.
     */
    protected function publishAgentSkills(): void
    {
        if ($this->option('skip-skills')) {
            return;
        }

        $path = (string) config('kinetix.skills_path', '.claude/skills');

        // Through the facade rather than `$this->callSilently()` so the command
        // also works when driven outside Artisan's console application.
        Artisan::call('vendor:publish', ['--tag' => 'kinetix-skills', '--force' => true]);

        $this->info("Published the Kinetix agent skills to {$path} (per-module guidance for coding agents).");
    }

    /**
     * Scaffold a dedicated `App\Providers\KinetixServiceProvider` (the Filament
     * pattern: keep all Kinetix registration out of AppServiceProvider) and
     * register it in bootstrap/providers.php. Runs by default on every install
     * (`--skip-provider` opts out). Idempotent — skips an existing provider
     * file and a provider already listed.
     */
    protected function scaffoldProvider(): void
    {
        $providerClass = 'App\\Providers\\KinetixServiceProvider';
        $providerPath  = app_path('Providers/KinetixServiceProvider.php');

        if (File::exists($providerPath)) {
            $this->info('App\\Providers\\KinetixServiceProvider already exists — skipping.');
        } else {
            File::ensureDirectoryExists(dirname($providerPath));
            File::put($providerPath, $this->providerStub());
            $this->info('Created app/Providers/KinetixServiceProvider.php');
        }

        $bootstrapPath = base_path('bootstrap/providers.php');

        if (! File::exists($bootstrapPath)) {
            $this->warn("bootstrap/providers.php not found — register {$providerClass}::class manually.");

            return;
        }

        $added = ServiceProvider::addProviderToBootstrapFile($providerClass, $bootstrapPath);

        if ($added) {
            $this->info('Registered App\\Providers\\KinetixServiceProvider in bootstrap/providers.php.');
        } else {
            $this->info('App\\Providers\\KinetixServiceProvider already registered in bootstrap/providers.php.');
        }
    }

    /**
     * The dedicated-provider stub. Resources under app/Kinetix/Resources are
     * auto-discovered, so the stub only shows the non-resource surface and the
     * "registrar class per module" convention that keeps this file — and
     * AppServiceProvider — from growing into a thousand-line dump.
     */
    protected function providerStub(): string
    {
        return <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Providers;

        use Illuminate\Support\ServiceProvider;

        /**
         * Dedicated home for ALL Kinetix registration — feature permissions, module
         * content, gates — keeping AppServiceProvider lean. As modules grow, don't
         * let this file grow with them: keep each module's content in its own small
         * registrar class under app/Kinetix (a class that only declares its
         * content, nothing else) and call it from boot() below.
         */
        class KinetixServiceProvider extends ServiceProvider
        {
            public function boot(): void
            {
                $this->registerPermissions();
                $this->registerModules();
            }

            /**
             * Resources under app/Kinetix/Resources are auto-discovered (see
             * config/kinetix.php `permissions.discover_path`) — never list them
             * here. Declare only the NON-resource features/abilities your app
             * gates on:
             *
             *     use Happones\Kinetix\Permissions\KinetixPermissions;
             *
             *     KinetixPermissions::feature('configuration')
             *         ->label('Configuration')
             *         ->abilities([
             *             'viewAny' => 'View business data',
             *             'update'  => 'Update business data',
             *         ]);
             */
            protected function registerPermissions(): void
            {
                //
            }

            /**
             * Optional Kinetix module content — one registrar class per module,
             * each a plain class with a static register() that only declares its
             * content through the module's facade:
             *
             *     \App\Kinetix\SpotlightSources::register();  // KinetixSpotlight::register([...])
             *     \App\Kinetix\ScheduledReports::register();  // KinetixReports::register(...)
             *     \App\Kinetix\GeneratedReports::register();  // KinetixReportsCenter::register(...)
             *     \App\Kinetix\PdfTemplates::register();      // KinetixPdf::register(...)
             *     \App\Kinetix\WebhookEvents::register();
             *     \App\Kinetix\OnboardingSteps::register();
             */
            protected function registerModules(): void
            {
                //
            }
        }
        PHP;
    }

    /**
     * Toasts need vue-sonner's stylesheet or they render as unstyled plain
     * text (no container, no animation, no colors) — and nothing else imports
     * it: `<KinetixToaster />` only re-themes the variables the stylesheet
     * reads. Appended AFTER the host's own imports so it loads after Tailwind,
     * exactly the ordering KinetixToaster's CSS-variable overrides expect.
     * Idempotent.
     */
    protected function ensureSonnerStylesheet(): void
    {
        $path = resource_path('css/app.css');

        if (! File::exists($path)) {
            $this->warn("resources/css/app.css not found — add `@import 'vue-sonner/style.css';` to your main stylesheet manually (toasts render unstyled without it).");

            return;
        }

        $existing = (string) File::get($path);

        if (str_contains($existing, 'vue-sonner/style.css')) {
            return;
        }

        // CSS requires @import before any other rule, so it can't just be
        // appended: insert AFTER the last existing @import (loading after
        // Tailwind, the ordering KinetixToaster's variable overrides expect),
        // or at the very top when there are none.
        $import = "@import 'vue-sonner/style.css'; /* Kinetix toasts — without this they render unstyled */";
        $lines  = preg_split('/\R/', $existing) ?: [];

        $lastImport = -1;
        foreach ($lines as $i => $line) {
            if (str_starts_with(trim($line), '@import')) {
                $lastImport = $i;
            }
        }

        array_splice($lines, $lastImport + 1, 0, [$import]);

        File::put($path, implode("\n", $lines));

        $this->info("Added `@import 'vue-sonner/style.css';` to resources/css/app.css (toast container/animation styles).");
    }

    /**
     * Wire `kinetix:upgrade` into composer's post-autoload-dump so published
     * components/translations re-publish on every composer install/update
     * (the Filament pattern — composer only runs root scripts, so the hook
     * must live in the host's composer.json).
     */
    protected function registerUpgradeHook(): void
    {
        $added = ComposerHook::ensure(
            base_path('composer.json'),
            'post-autoload-dump',
            '@php artisan kinetix:upgrade',
        );

        if ($added) {
            $this->info('Registered `@php artisan kinetix:upgrade` in composer.json (post-autoload-dump) — published components/translations now refresh on composer update.');
        }
    }

    /**
     * Keep the host's formatters off the vendor-managed publishes. The Laravel
     * Vue starter kit ships `prettier --write resources/` and repo-wide
     * eslint, which reformat the published copies — and `kinetix:upgrade`
     * overwrites them again on the next composer update, so a default install
     * drifts on every `format` run. Appends the publish targets to
     * `.prettierignore` (idempotent) and prints the eslint flat-config
     * equivalent (that file can't be edited safely by a script).
     */
    protected function protectPublishedPathsFromFormatters(): void
    {
        // Every entry matches a `kinetix`-prefixed name: publishes share the host's
        // own directories, so a vendor-managed file must never be able to collide
        // with (or be mistaken for) one of the app's files.
        $entries = [
            'resources/js/components/kinetix/',
            'resources/js/composables/useKinetix*.ts',
            'resources/js/composables/kinetix*.ts',
            'resources/js/stores/kinetix*.ts',
            'resources/js/plugins/kinetix*.ts',
            'resources/js/icons/kinetixBrands*',
            'resources/js/types/kinetix.ts',
            'resources/js/vue-i18n-locales*',
            rtrim((string) config('kinetix.skills_path', '.claude/skills'), '/').'/kinetix-*/',
        ];

        $path     = base_path('.prettierignore');
        $existing = File::exists($path) ? (string) File::get($path) : '';
        $lines    = array_map('trim', preg_split('/\R/', $existing) ?: []);
        $missing  = array_values(array_diff($entries, $lines));

        if ($missing === []) {
            return;
        }

        $block = ($existing === '' ? '' : rtrim($existing, "\n")."\n\n")
            ."# Kinetix publishes are vendor-managed (kinetix:upgrade overwrites them)\n"
            .implode("\n", $missing)."\n";

        File::put($path, $block);
        $this->info('Added the Kinetix publish paths to .prettierignore (vendor-managed files stay formatter-free).');
        $this->comment('If eslint lints resources/, mirror them in your eslint config:');
        $this->line("  { ignores: ['resources/js/components/kinetix/**', 'resources/js/composables/useKinetix*', 'resources/js/composables/kinetix*', 'resources/js/stores/kinetix*', 'resources/js/plugins/kinetix*', 'resources/js/icons/kinetixBrands*', 'resources/js/types/kinetix.ts', 'resources/js/vue-i18n-locales*'] }");
    }

    /**
     * Keep compiled output out of git. The Vue i18n bundle is regenerated by
     * `kinetix:upgrade` on every `composer install`, so a versioned copy is
     * rewritten on every branch that touches a translation — pure merge-conflict
     * noise for a build artifact. Same for the published Kinetix declarations,
     * which the package owns.
     */
    protected function gitignoreGeneratedOutput(): void
    {
        $entries = [
            'resources/js/vue-i18n-locales.generated.*',
            'resources/js/types/kinetix.ts',
        ];

        $path = base_path('.gitignore');

        if (! File::exists($path)) {
            return;
        }

        $existing = (string) File::get($path);
        $lines    = array_map('trim', preg_split('/\R/', $existing) ?: []);
        $missing  = array_values(array_diff($entries, $lines));

        if ($missing === []) {
            return;
        }

        File::put($path, rtrim($existing, "\n")."\n\n"
            ."# Kinetix regenerates these on every composer install\n"
            .implode("\n", $missing)."\n");

        $this->info('Added the Kinetix generated output to .gitignore: '.implode(', ', $missing).'.');
        $this->comment('Already tracked? Run: git rm --cached '.implode(' ', $missing));
    }

    /**
     * Run the detected package manager's install. Extracted so it can be
     * overridden (e.g. in tests) without shelling out.
     */
    protected function runPackageInstall(string $packageManager): int
    {
        $exitCode = 0;
        $output   = [];
        exec('cd '.base_path()." && {$packageManager} install", $output, $exitCode);

        return $exitCode;
    }
}
