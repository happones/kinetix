<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Happones\Kinetix\Support\PublishedFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * Re-publish the Kinetix published assets that change between releases —
 * meant to run from the host's composer `post-autoload-dump` (wired by
 * `kinetix:install`), like Filament's `filament:upgrade`:
 *
 *     "post-autoload-dump": ["@php artisan kinetix:upgrade"]
 *
 * Only refreshes what the app has ADOPTED: a tag whose publish target does not
 * exist yet is skipped, so the hook never dumps files into apps that didn't
 * opt in. Published Kinetix files are vendor-managed copies — local edits are
 * overwritten here, so customize via wrappers/slots/config instead. Files that
 * *did* carry local edits are named in the output rather than vanishing quietly.
 */
class UpgradeCommand extends Command
{
    protected $signature = 'kinetix:upgrade';

    protected $description = 'Re-publish Kinetix components, translations and agent skills after a composer update';

    public function handle(): int
    {
        // Detect local edits BEFORE overwriting them, so they can be reported.
        $drifted = PublishedFiles::drifted();

        $refreshed = [];

        // Vue components (+ composables, stores, TS types).
        if (File::isDirectory(resource_path('js/components/kinetix'))) {
            $this->callSilently('vendor:publish', ['--tag' => 'kinetix-components', '--force' => true]);
            $refreshed[] = 'components';
        }

        // PHP translations — and the compiled Vue i18n bundle when the
        // generator package is installed.
        if (File::exists(lang_path('en/kinetix.php'))) {
            $this->callSilently('vendor:publish', ['--tag' => 'kinetix-translations', '--force' => true]);
            $refreshed[] = 'translations';

            if (array_key_exists('vue-i18n:generate', Artisan::all())) {
                // Forward the host's compile flags (e.g. --multi-locales) so
                // the regenerated bundle is the one the app actually imports.
                $this->callSilently(
                    'vue-i18n:generate',
                    (array) config('kinetix.translations.vue_i18n_options', []),
                );
                $refreshed[] = 'vue-i18n bundle';
            }
        }

        // Agent skills — refreshed only if the app adopted them, so the hook
        // never creates a skills directory in a project that doesn't want one.
        $skillsPath = base_path((string) config('kinetix.skills_path', '.claude/skills'));

        if (File::isDirectory($skillsPath.'/kinetix-permissions')) {
            $this->callSilently('vendor:publish', ['--tag' => 'kinetix-skills', '--force' => true]);
            $refreshed[] = 'agent skills';
        }

        if ($refreshed === []) {
            $this->info('Kinetix: nothing to upgrade — no published components/translations/skills found.');

            return self::SUCCESS;
        }

        $this->info('Kinetix upgraded: '.implode(', ', $refreshed).'.');

        $this->reportOverwrittenEdits($drifted);
        $this->warnAboutDuplicateI18nBundles();
        $this->warnAboutLegacyTypesBarrel();

        $this->comment('Rebuild your frontend when convenient (npm run build / composer run dev).');

        return self::SUCCESS;
    }

    /**
     * @param array<int, string> $drifted
     */
    protected function reportOverwrittenEdits(array $drifted): void
    {
        if ($drifted === []) {
            return;
        }

        $this->newLine();
        $this->warn(count($drifted).' published file(s) had local edits and were overwritten:');

        foreach ($drifted as $path) {
            $this->line("  <fg=red>~</> {$path}");
        }

        $this->line('  <fg=gray>Published files are vendor-managed. To keep a change, move it into a</>');
        $this->line('  <fg=gray>wrapper component, a slot, or config — not the published copy.</>');
    }

    /**
     * Vite resolves `.js` before `.ts`, so with both bundles present the app
     * compiles the one `vue-i18n:generate` no longer writes — new translation
     * keys silently never reach the UI.
     */
    protected function warnAboutDuplicateI18nBundles(): void
    {
        if (count(PublishedFiles::i18nBundles()) < 2) {
            return;
        }

        $this->newLine();
        $this->warn('Two Vue i18n bundles are present:');

        foreach (PublishedFiles::i18nBundles() as $path) {
            $this->line("  <fg=yellow>·</> {$path}");
        }

        $this->line('  <fg=gray>Vite resolves .js before .ts, so the compiled bundle is whichever one</>');
        $this->line('  <fg=gray>`vue-i18n:generate` is NOT writing (it defaults to --format=ts while the</>');
        $this->line('  <fg=gray>generator config points `jsFile` at the .js). Delete the stale one and</>');
        $this->line('  <fg=gray>mirror your flags in `kinetix.translations.vue_i18n_options`.</>');
    }

    /**
     * Kinetix used to publish its declarations as `types/index.ts`, the name the
     * Laravel starter kits use for the app's own barrel.
     */
    protected function warnAboutLegacyTypesBarrel(): void
    {
        $legacy = PublishedFiles::legacyTypesBarrel();

        if ($legacy === null) {
            return;
        }

        $this->newLine();
        $this->warn("{$legacy} is no longer managed by Kinetix (v0.119.0).");
        $this->line('  <fg=gray>Kinetix now publishes to resources/js/types/kinetix.ts. That file is the</>');
        $this->line('  <fg=gray>starter kit\'s own barrel — restore your app types there (export * from</>');
        $this->line('  <fg=gray>\'./auth\', …) and import Kinetix types from \'@/types/kinetix\'.</>');
    }
}
