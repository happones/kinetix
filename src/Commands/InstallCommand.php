<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Happones\Kinetix\Commands\Concerns\WritesGeneratedFiles;
use Happones\Kinetix\Support\ComposerHook;
use Happones\Kinetix\Support\InertiaEntryFile;
use Happones\Kinetix\Support\PublishedFiles;
use Happones\Kinetix\Support\StylesheetLines;
use Happones\Kinetix\Support\TailwindSourceExclusions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;

class InstallCommand extends Command
{
    use WritesGeneratedFiles;

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
        {--skip-skills : Do not publish the bundled agent skills}
        {--lazy-locales : Load each locale\'s messages on demand (needs vue-i18n:generate --multi-locales)}';

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
            // Loaded with a dynamic import once a list passes its threshold,
            // but Vite still resolves dynamic specifiers at build time, so the
            // host's build fails without it — a required peer.
            '@tanstack/vue-virtual' => '^3.0.0',
            // KinetixNotifications / useKinetixPresence import it dynamically,
            // only when real-time is on and Echo is configured. Vite resolves
            // that specifier at build time, so it stays a required peer even
            // for apps that never broadcast.
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

        // Update app.ts / app.js — all or nothing: an entry this can't wire
        // safely is left exactly as it was, and the snippet is printed.
        $content = File::get($appFile);
        $lazy    = (bool) $this->option('lazy-locales');
        $file    = "resources/js/app.{$ext}";

        if (str_contains($content, 'vue-i18n')) {
            if ($lazy) {
                $this->warn("{$file} already registers vue-i18n, so it was left as it is. To load locales on demand, switch it by hand:");
                $this->printLazyLocalesSnippet();
            } else {
                $this->info("{$file} is already configured.");
            }

            return $this->finishInstall(lazyLocales: $lazy);
        }

        $wired = $lazy ? $this->wireEntry($content, $ext, lazy: true) : null;

        if ($wired !== null) {
            File::put($appFile, $wired);
            $this->info("Updated {$file} with on-demand locales and Pinia registration.");

            return $this->finishInstall(lazyLocales: true);
        }

        $wired = $this->wireEntry($content, $ext, lazy: false);

        if ($lazy) {
            $this->warn($wired === null
                ? "Could not wire {$file} safely (createInertiaApp() needs one `resolve:` and a readable setup() or withApp()), so it was left as it is. Wire it by hand:"
                : "Could not find a single `resolve:` (or a readable setup() / withApp()) in createInertiaApp() in {$file}, so the eager locale wiring was written instead. To load locales on demand, switch it by hand:");
            $this->printLazyLocalesSnippet();
        }

        if ($wired === null) {
            if (! $lazy) {
                $this->warn("Could not wire {$file} safely, so it was left as it is. Register vue-i18n and Pinia by hand:");
            }

            $this->printEagerSnippet($ext);

            return $this->finishInstall(lazyLocales: $lazy);
        }

        File::put($appFile, $wired);
        $this->info("Updated {$file} with i18n and Pinia registration.");

        return $this->finishInstall(lazyLocales: $lazy);
    }

    /**
     * The steps after the entry file: hooks, stylesheets, the provider and the
     * published-files baseline.
     */
    protected function finishInstall(bool $lazyLocales): int
    {
        $this->registerUpgradeHook();
        $this->ensureSonnerStylesheet();
        $this->ensureStatusTokens();
        $this->excludeAgentSkillsFromTailwind();
        $this->protectPublishedPathsFromFormatters();
        $this->gitignoreGeneratedOutput();

        if (! $this->option('skip-provider')) {
            $this->scaffoldProvider();
        }

        // Baseline the just-published files so `kinetix:upgrade` can tell
        // local edits apart from upstream changes.
        PublishedFiles::record();

        $this->info('Kinetix installation and configuration completed successfully!');

        if ($lazyLocales) {
            $this->printLazyLocalesNextSteps();
        }

        return self::SUCCESS;
    }

    /**
     * The entry file with vue-i18n and Pinia wired in — eager, or on demand
     * (`$lazy`: `resolve` wrapped, messages from the plugin's cache) — or
     * null when it can't be done safely. Inertia calls `setup()` INSTEAD of
     * `withApp()` when both are given, so the wiring follows the entry's
     * shape and never adds `withApp` next to a `setup`:
     *
     *  - neither: a `withApp(app, { page })` property is added;
     *  - `withApp`: the i18n is built at the start of its body;
     *  - `setup`: the i18n is built at the start of its body from
     *    `props.initialPage`, and `.use(i18n).use(pinia)` is chained onto its
     *    one `createApp(…)` call.
     *
     * Everything is located by `InertiaEntryFile`'s scanner on the first
     * `createInertiaApp({ … })` object, so comments and strings never match.
     * An object it can't fully read (a spread, `async setup()`, a getter) is
     * left alone: it might hold a `setup` the scanner can't see.
     */
    protected function wireEntry(string $content, string $ext, bool $lazy): ?string
    {
        $shape = InertiaEntryFile::describe($content);

        if ($shape === null || ($shape['setup'] && $shape['withApp'])) {
            return null;
        }

        $source = $lazy ? InertiaEntryFile::wrapResolve($content, 'withKinetixLocales') : $content;

        if ($source === null) {
            return null;
        }

        $usesPinia = InertiaEntryFile::callsUse($content, 'pinia');

        // Lazy: `attachKinetixI18n(i18n);` closes the block, the uses follow it.
        $gap = $lazy ? "\n" : "\n\n";

        if ($shape['setup']) {
            $props = InertiaEntryFile::setupPropsExpression($source);

            if ($props === null) {
                return null;
            }

            $source = InertiaEntryFile::chainOntoCreateApp($source, '.use(i18n)'.($usesPinia ? '' : '.use(pinia)'));
            $source = $source === null ? null : InertiaEntryFile::prependToBody($source, 'setup', $this->i18nInstance("{$props}.initialPage", $ext, $lazy)."\n");
        } elseif ($shape['withApp']) {
            $parameters = InertiaEntryFile::withAppParameters($source);

            if ($parameters === null) {
                return null;
            }

            $uses   = "{$parameters['app']}.use(i18n);".($usesPinia ? '' : "\n{$parameters['app']}.use(pinia);");
            $source = InertiaEntryFile::prependToBody($source, 'withApp', $this->i18nInstance($parameters['page'], $ext, $lazy).$gap.$uses."\n");
        } else {
            $body   = $this->i18nInstance('page', $ext, $lazy).$gap."app.use(i18n);\napp.use(pinia);";
            $source = InertiaEntryFile::addProperty($source, "withApp(app, { page }) {\n".$this->indentLines($body, '    ')."\n}");
        }

        if ($source === null) {
            return null;
        }

        $imports = $lazy
            ? [
                "import { createI18n } from 'vue-i18n';",
                "import { attachKinetixI18n, kinetixLoadedMessages, kinetixLocaleLoaderFromGlob, kinetixPageLocale, registerKinetixLocales, withKinetixLocales } from '@/plugins/kinetixLocales';",
            ]
            : ["import { createI18n } from 'vue-i18n';"];

        if (! str_contains($source, '@/stores') && ! str_contains($source, './stores')) {
            $imports[] = "import pinia from '@/stores';";
        }

        if (! $lazy && ! str_contains($source, 'vue-i18n-locales')) {
            $imports[] = "import Locale from './vue-i18n-locales';";
        }

        $lines = explode("\n", $source);
        $first = null;

        foreach ($lines as $index => $line) {
            if (str_starts_with(trim($line), 'import ')) {
                $first = $index;

                break;
            }
        }

        array_splice($lines, $first ?? 0, 0, $imports);

        if ($lazy) {
            array_splice($lines, $this->lineAfterLastImport($lines), 0, ['', ...explode("\n", $this->registerLocalesCall())]);
        }

        return implode("\n", $lines);
    }

    /**
     * `const i18n = createI18n({ … })` for the page expression `$page`, with
     * no base indentation.
     */
    protected function i18nInstance(string $page, string $ext, bool $lazy): string
    {
        if ($lazy) {
            $fallback = $this->fallbackLocale();

            return <<<JS
const i18n = createI18n({
    legacy: false,
    locale: kinetixPageLocale({$page}),
    fallbackLocale: '{$fallback}',
    messages: kinetixLoadedMessages(),
});

attachKinetixI18n(i18n);
JS;
        }

        // The TypeScript cast only in a .ts entry: in .js it's a syntax error.
        $locale = $ext === 'ts' ? "{$page}.props.locale as string | undefined" : "{$page}.props.locale";

        return <<<JS
const i18n = createI18n({
    legacy: false,
    locale: {$locale},
    messages: Locale,
});
JS;
    }

    protected function indentLines(string $code, string $indent): string
    {
        return implode("\n", array_map(static fn (string $line): string => $line === '' ? '' : $indent.$line, explode("\n", $code)));
    }

    /**
     * The eager wiring, for an entry the installer didn't change.
     */
    protected function printEagerSnippet(string $ext): void
    {
        $cast = $ext === 'ts' ? ' as string | undefined' : '';

        $this->line('');
        $this->line("import { createI18n } from 'vue-i18n';");
        $this->line("import pinia from '@/stores';");
        $this->line("import Locale from './vue-i18n-locales';");
        $this->line('');
        $this->line('createInertiaApp({');
        $this->line('    // …');
        $this->line('    withApp(app, { page }) {');
        $this->line('        const i18n = createI18n({');
        $this->line('            legacy: false,');
        $this->line("            locale: page.props.locale{$cast},");
        $this->line('            messages: Locale,');
        $this->line('        });');
        $this->line('');
        $this->line('        app.use(i18n);');
        $this->line('        app.use(pinia);');
        $this->line('    },');
        $this->line('});');
        $this->line('');
        $this->line('With a setup() instead (Inertia then skips withApp): build the same i18n inside setup() from');
        $this->line('props.initialPage and chain .use(i18n).use(pinia) onto its createApp(…).');
    }

    /**
     * The index of the line after the last top-level import statement,
     * multi-line imports included.
     *
     * @param list<string> $lines
     */
    protected function lineAfterLastImport(array $lines): int
    {
        $after    = 0;
        $inImport = false;

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);

            if (! $inImport && str_starts_with($trimmed, 'import ')) {
                $inImport = true;
            }

            if (! $inImport) {
                continue;
            }

            // Done once the module specifier is on this line: `… from '…'`,
            // or a side-effect import `import '…'`.
            if (preg_match('/(\bfrom\s*[\'"]|^import\s*[\'"])/', $trimmed) === 1) {
                $inImport = false;
                $after    = $index + 1;
            }
        }

        return $after;
    }

    /**
     * Only the generator's per-locale files: they hold the converted messages
     * (`:name` → `{name}`, plural tags, `@` escaping). Root `lang/*.json`
     * catalogs are never globbed raw here; `--multi-locales` doesn't emit them,
     * and docs/locale.md covers the opt-in second glob and its caveats.
     */
    protected function registerLocalesCall(): string
    {
        $directory = $this->localesGlobDirectory();
        $fallback  = $this->fallbackLocale();

        return <<<JS
registerKinetixLocales({
    loader: kinetixLocaleLoaderFromGlob(import.meta.glob(['{$directory}*.ts', '!{$directory}index.ts'])),
    fallbackLocale: '{$fallback}',
});
JS;
    }

    /**
     * Where `vue-i18n:generate --multi-locales` writes the per-locale files
     * (`vue-i18n-generator.jsPath`), relative to the entry file's directory;
     * `./locales/` by default.
     */
    protected function localesGlobDirectory(): string
    {
        $jsPath = config('vue-i18n-generator.jsPath');

        if (! is_string($jsPath) || trim($jsPath) === '') {
            return './locales/';
        }

        $base   = rtrim(str_replace('\\', '/', base_path()), '/');
        $target = str_replace('\\', '/', $jsPath);
        $target = str_starts_with($target, $base.'/') ? $target : $base.'/'.ltrim($target, '/');

        // A file path (the single-bundle default) names its directory.
        if (pathinfo($target, PATHINFO_EXTENSION) !== '') {
            $target = dirname($target);
        }

        return $this->relativeToEntry($target);
    }

    /**
     * An absolute directory inside the app as a glob prefix relative to
     * `resources/js` (the entry file's directory), with a trailing slash.
     */
    protected function relativeToEntry(string $target): string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/');

        $from = explode('/', trim(substr($base.'/resources/js', strlen($base)), '/'));
        $to   = explode('/', trim(substr(rtrim($target, '/'), strlen($base)), '/'));

        while ($from !== [] && $to !== [] && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }

        $relative = str_repeat('../', count($from)).implode('/', $to);

        if ($relative === '') {
            return './';
        }

        return (str_starts_with($relative, '../') ? '' : './').rtrim($relative, '/').'/';
    }

    protected function fallbackLocale(): string
    {
        $fallback = config('app.fallback_locale', 'en');

        return is_string($fallback) && preg_match('/^[A-Za-z0-9_-]+$/', $fallback) === 1 ? $fallback : 'en';
    }

    /**
     * The on-demand wiring, for an entry file the installer didn't change.
     */
    protected function printLazyLocalesSnippet(): void
    {
        $fallback = $this->fallbackLocale();

        $this->line('');
        $this->line("import { createI18n } from 'vue-i18n';");
        $this->line("import { attachKinetixI18n, kinetixLoadedMessages, kinetixLocaleLoaderFromGlob, kinetixPageLocale, registerKinetixLocales, withKinetixLocales } from '@/plugins/kinetixLocales';");
        $this->line('');
        $this->line($this->registerLocalesCall());
        $this->line('');
        $this->line('createInertiaApp({');
        $this->line('    // Your existing resolver, wrapped: the first paint waits for the messages.');
        $this->line('    resolve: withKinetixLocales(/* your resolve */),');
        $this->line('    withApp(app, { page }) {');
        $this->line('        const i18n = createI18n({');
        $this->line('            legacy: false,');
        $this->line('            locale: kinetixPageLocale(page),');
        $this->line("            fallbackLocale: '{$fallback}',");
        $this->line('            messages: kinetixLoadedMessages(),');
        $this->line('        });');
        $this->line('');
        $this->line('        attachKinetixI18n(i18n);');
        $this->line('        app.use(i18n);');
        $this->line('        app.use(pinia);');
        $this->line('    },');
        $this->line('    // …');
        $this->line('});');
        $this->line('');
        $this->line('Drop the eager `import Locale from \'./vue-i18n-locales\'` and its `messages: Locale`.');
        $this->line('With a setup() instead (Inertia then skips withApp): build the same i18n inside setup() with');
        $this->line('kinetixPageLocale(props.initialPage) and chain .use(i18n).use(pinia) onto its createApp(…).');
        $this->line('Without a resolve: (pages resolved by the @inertiajs/vite plugin), add one to wrap:');
        $this->line("resolve: withKinetixLocales((name) => resolvePageComponent(`./pages/\${name}.vue`, import.meta.glob('./pages/**/*.vue'))),");
    }

    protected function printLazyLocalesNextSteps(): void
    {
        $this->line('');
        $this->info('On-demand locales — next steps:');
        $this->line('  1. composer require happones/laravel-vue-i18n-generator');
        $this->line('  2. php artisan vue-i18n:generate --multi-locales');
        $this->line("  3. In config/kinetix.php set 'translations' => ['vue_i18n_options' => ['--multi-locales' => true]], so upgrades regenerate the per-locale files.");
        $this->line('  4. Every other entry (ssr.ts, a public.ts…) needs the same registerKinetixLocales() call, withKinetixLocales() around resolve and a createI18n() inside withApp (one per request on SSR). The installer never edits them; an entry left eager keeps every locale in its bundle.');
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
            $this->putGenerated($providerPath, $this->providerStub());
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
             *     \App\Kinetix\Entitlements::register();      // KinetixEntitlements::define(...)
             *     \App\Kinetix\FeatureFlags::register();      // KinetixFeatures::define(...)
             *
             * A feature gated by MORE THAN ONE layer (a flag AND a plan AND a
             * role) belongs in an entitlement, declared once, rather than an
             * `&&` chain re-typed in the controller and again in every button:
             *
             *     KinetixEntitlements::define('projects.create')
             *         ->plan('projects')
             *         ->limit('projects', [ProjectCounter::class, 'for'])
             *         ->permission('projects.create');
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
        // A multi-line `@import … layer(base);` is never split, and the
        // file's own line endings are kept.
        $import  = "@import 'vue-sonner/style.css'; /* Kinetix toasts — without this they render unstyled */";
        $updated = StylesheetLines::insertAfterLast($existing, [$import], ['@import']);

        if ($updated === null) {
            $this->warn("Could not find a safe place for `@import 'vue-sonner/style.css';` in resources/css/app.css — add it after your other @import lines (toasts render unstyled without it).");

            return;
        }

        File::put($path, $updated);

        $this->info("Added `@import 'vue-sonner/style.css';` to resources/css/app.css (toast container/animation styles).");
    }

    /**
     * Keep the just-published agent skills out of Tailwind's class scan (see
     * TailwindSourceExclusions). When it can't — Tailwind older than 4.1, no
     * node_modules, no app.css — it says so, with the line to add later.
     */
    protected function excludeAgentSkillsFromTailwind(): void
    {
        $reason = TailwindSourceExclusions::skipReason();

        if ($reason === null && TailwindSourceExclusions::missing() === []) {
            return;
        }

        if ($reason === null) {
            $reason = TailwindSourceExclusions::placementProblem();
        }

        if ($reason === null) {
            $added = TailwindSourceExclusions::ensure();

            $this->info('Excluded the agent skills from Tailwind\'s scan in resources/css/app.css ('.implode(', ', $added).').');

            return;
        }

        // Nothing to say when it's turned off or there is nothing to exclude.
        if (! (bool) config('kinetix.tailwind.exclude_agent_skills', true) || TailwindSourceExclusions::directories() === []) {
            return;
        }

        $lines = array_map(
            static fn (string $path): string => "@source not '{$path}';",
            TailwindSourceExclusions::expected(),
        );

        $this->line("  <fg=gray>Skipped excluding the agent skills from Tailwind's scan: {$reason}.</>");
        $this->line('  <fg=gray>Add them at the top level of resources/css/app.css, after its @import lines: '.implode(' ', $lines).'</>');
    }

    /**
     * Kinetix's status colors (success · warning · info), which shadcn
     * doesn't ship: badges, alerts, stat chips, modals, notifications and
     * action colors use them, and without the `@theme` mapping Tailwind
     * generates no `bg-success` / `text-warning` / `border-info` at all.
     * Appended to the host's stylesheet when it maps none of them (an app on
     * kinetix.css already has them). Idempotent.
     */
    protected function ensureStatusTokens(): void
    {
        $path = resource_path('css/app.css');

        if (! File::exists($path)) {
            return;
        }

        $existing = (string) File::get($path);

        if (str_contains($existing, '--color-success') || str_contains($existing, 'kinetix.css')) {
            return;
        }

        File::put($path, rtrim($existing).'

'.self::STATUS_TOKENS);

        $this->info('Added the Kinetix status tokens (success · warning · info) to resources/css/app.css.');
    }

    /**
     * The status tokens, AA in both themes: as text on the page, on their own
     * 10% tint (badges) and as a fill under their `-foreground`.
     */
    protected const STATUS_TOKENS = <<<'CSS'
        /* Kinetix status colors (success · warning · info): badges, alerts, stat
           chips, modals and action colors. Re-skin them here, checking 4.5:1 in
           both themes. */
        :root {
            --success: 142 72% 27%;
            --success-foreground: 0 0% 100%;
            --warning: 26 90% 33%;
            --warning-foreground: 0 0% 100%;
            --info: 201 96% 32%;
            --info-foreground: 0 0% 100%;
        }

        .dark {
            --success: 142 69% 58%;
            --success-foreground: 142 80% 10%;
            --warning: 43 96% 56%;
            --warning-foreground: 26 83% 14%;
            --info: 198 93% 60%;
            --info-foreground: 204 80% 16%;
        }

        @theme inline {
            --color-success: hsl(var(--success));
            --color-success-foreground: hsl(var(--success-foreground));
            --color-warning: hsl(var(--warning));
            --color-warning-foreground: hsl(var(--warning-foreground));
            --color-info: hsl(var(--info));
            --color-info-foreground: hsl(var(--info-foreground));
        }

        CSS;

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
