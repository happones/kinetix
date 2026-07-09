<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

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
 * overwritten here, so customize via wrappers/slots/config instead.
 */
class UpgradeCommand extends Command
{
    protected $signature = 'kinetix:upgrade';

    protected $description = 'Re-publish Kinetix components and translations after a composer update';

    public function handle(): int
    {
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
                $this->callSilently('vue-i18n:generate');
                $refreshed[] = 'vue-i18n bundle';
            }
        }

        if ($refreshed === []) {
            $this->info('Kinetix: nothing to upgrade — no published components/translations found.');

            return self::SUCCESS;
        }

        $this->info('Kinetix upgraded: '.implode(', ', $refreshed).'.');
        $this->comment('Rebuild your frontend when convenient (npm run build / composer run dev).');

        return self::SUCCESS;
    }
}
