<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kinetix:install
        {--charts : Also install chart/widget dependencies (@unovis/vue, @unovis/ts)}
        {--broadcasting : Also install real-time notification deps (@laravel/echo-vue)}';

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

        $this->info('Kinetix installation and configuration completed successfully!');

        return self::SUCCESS;
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
