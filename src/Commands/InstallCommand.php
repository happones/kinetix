<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Happones\Kinetix\Support\ComposerHook;
use Illuminate\Console\Command;
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
        {--tanstack : Also install client-side table + list virtualization deps (@tanstack/vue-table, @tanstack/vue-virtual)}
        {--broadcasting : Also install real-time notification deps (@laravel/echo-vue)}
        {--provider : Scaffold a dedicated App\Providers\KinetixServiceProvider and register it}';

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
        ];

        // Opt-in, feature-specific dependencies.
        if ($this->option('charts')) {
            $dependencies['@unovis/vue'] = '^1.3.0';
            $dependencies['@unovis/ts']  = '^1.3.0';
        }

        // Client-side (TanStack) tables and the long-list virtualization used by
        // KinetixComments / KinetixKanban. Both are optional peers — only apps
        // that opt into those features need them.
        if ($this->option('tanstack')) {
            $dependencies['@tanstack/vue-table']   = '^8.0.0';
            $dependencies['@tanstack/vue-virtual'] = '^3.0.0';
        }

        if ($this->option('broadcasting')) {
            $dependencies['@laravel/echo-vue'] = '^2.3.0';
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

        if ($this->option('provider')) {
            $this->scaffoldProvider();
        }

        $this->info('Kinetix installation and configuration completed successfully!');

        return self::SUCCESS;
    }

    /**
     * Scaffold a dedicated `App\Providers\KinetixServiceProvider` (the Filament
     * pattern: keep all Kinetix registration out of AppServiceProvider) and
     * register it in bootstrap/providers.php. Idempotent — skips an existing
     * provider file and a provider already listed.
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
     * "registrar class per module" convention.
     */
    protected function providerStub(): string
    {
        return <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Providers;

        use Happones\Kinetix\Permissions\KinetixPermissions;
        use Illuminate\Support\ServiceProvider;

        /**
         * Dedicated home for all Kinetix registration, keeping AppServiceProvider
         * lean. Prefer one small "registrar" class per module (a class that just
         * declares/returns its content) and call it from boot().
         */
        class KinetixServiceProvider extends ServiceProvider
        {
            public function boot(): void
            {
                $this->registerPermissions();
                // $this->registerModules();
            }

            /**
             * Resources under app/Kinetix/Resources are auto-discovered (see
             * config/kinetix.php `permissions.discover_path`). Declare here only the
             * non-resource features/abilities your app needs.
             */
            protected function registerPermissions(): void
            {
                // KinetixPermissions::feature('configuration')
                //     ->label('Configuration')
                //     ->abilities([
                //         'viewAny' => 'View business data',
                //         'update'  => 'Update business data',
                //     ]);
            }

            /**
             * Register optional Kinetix module content. Keep each module's content
             * in its own registrar class, then call it here:
             *
             *     \App\Kinetix\WebhookEvents::register();
             *     \App\Kinetix\OnboardingSteps::register();
             *     \App\Kinetix\SpotlightLinks::register();
             */
            // protected function registerModules(): void
            // {
            //     //
            // }
        }
        PHP;
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
