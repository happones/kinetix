<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Captures the Help Center screenshots with Playwright and uploads them to the
 * configured disk. The page manifest lives in `kinetix.help.screenshots`
 * (pages, login selectors, viewport); credentials travel via env
 * (`KINETIX_SCREENSHOT_EMAIL` / `KINETIX_SCREENSHOT_PASSWORD`), never argv.
 *
 * Requires Playwright in the HOST app:
 *     npm i -D playwright && npx playwright install chromium
 */
class HelpScreenshotsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kinetix:help-screenshots
                            {--only= : Capture only these page names (comma-separated)}
                            {--locale= : Store the captures for this locale (articles in it get them instead of the shared ones)}
                            {--keep-local : Keep the local PNGs after uploading}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Capture the Help Center screenshots with Playwright and store them on the configured disk';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $pages = $this->pages();

        if ($pages === []) {
            $this->error('No pages configured. Add them under `kinetix.help.screenshots.pages` (name => path).');

            return self::FAILURE;
        }

        $script = $this->runnerScript();

        if ($script === null) {
            $this->error('Screenshot runner not found. Publish it first:');
            $this->line('  php artisan vendor:publish --tag=kinetix-help-screenshots');

            return self::FAILURE;
        }

        $outDir       = storage_path('framework/kinetix-help-screenshots');
        $manifestPath = $outDir.'/manifest.json';
        File::ensureDirectoryExists($outDir);
        File::put($manifestPath, (string) json_encode($this->manifest($pages, $outDir), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

        $node   = (string) config('kinetix.help.screenshots.node_binary', 'node');
        $result = Process::env([
            'KINETIX_SCREENSHOT_EMAIL'    => (string) config('kinetix.help.screenshots.credentials.email', ''),
            'KINETIX_SCREENSHOT_PASSWORD' => (string) config('kinetix.help.screenshots.credentials.password', ''),
        ])->timeout(300)->run(
            [$node, $script, $manifestPath],
            function (string $type, string $output): void {
                $this->output->write($output);
            },
        );

        if (! $result->successful()) {
            $this->error('Playwright run failed. Make sure Playwright is installed in your app:');
            $this->line('  npm i -D playwright && npx playwright install chromium');
            $this->line('You can also run the capture manually:');
            $this->line("  {$node} {$script} {$manifestPath}");

            return self::FAILURE;
        }

        return $this->upload($outDir);
    }

    /**
     * Normalized page manifest entries, honoring --only.
     *
     * @return array<int, array{name: string, path: string, full_page?: bool, delay?: int}>
     */
    protected function pages(): array
    {
        $configured = (array) config('kinetix.help.screenshots.pages', []);
        $only       = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));

        $pages = [];

        foreach ($configured as $name => $definition) {
            if ($only !== [] && ! in_array($name, $only, true)) {
                continue;
            }

            $pages[] = is_array($definition)
                ? ['name' => (string) $name, ...$definition]
                : ['name' => (string) $name, 'path' => (string) $definition];
        }

        return $pages;
    }

    /**
     * @param  array<int, array<string, mixed>> $pages
     * @return array<string, mixed>
     */
    protected function manifest(array $pages, string $outDir): array
    {
        return [
            'base_url'  => config('kinetix.help.screenshots.base_url') ?? config('app.url'),
            'out_dir'   => $outDir,
            'viewport'  => config('kinetix.help.screenshots.viewport', ['width' => 1440, 'height' => 900]),
            'delay'     => (int) config('kinetix.help.screenshots.delay', 700),
            'selectors' => config('kinetix.help.screenshots.selectors', []),
            'pages'     => $pages,
        ];
    }

    /**
     * Prefer the published runner (its ESM playwright import resolves against
     * the HOST node_modules); fall back to the vendor copy.
     */
    protected function runnerScript(): ?string
    {
        $published = base_path('scripts/kinetix-help-screenshots.mjs');

        if (File::exists($published)) {
            return $published;
        }

        $vendor = realpath(__DIR__.'/../../scripts/help-screenshots.mjs');

        return $vendor !== false ? $vendor : null;
    }

    /**
     * Upload the captured PNGs to the configured disk.
     */
    protected function upload(string $outDir): int
    {
        $disk   = config('kinetix.help.screenshots.disk') ?? config('kinetix.filesystem.disk', 'public');
        $prefix = trim((string) config('kinetix.help.screenshots.path_prefix', 'help/screenshots'), '/');
        $files  = File::glob($outDir.'/*.png');

        // A localized run lands in its own folder; articles written in that
        // locale are served from there, everything else keeps the shared set.
        $locale = (string) ($this->option('locale') ?? '');

        if ($locale !== '') {
            if (! preg_match('/^[a-z]{2}([_-][A-Za-z]{2,4})?$/', $locale)) {
                $this->error("Invalid locale [{$locale}]. Use a code like `es` or `pt_BR`.");

                return self::FAILURE;
            }

            $prefix .= '/'.$locale;
        }

        if ($files === []) {
            $this->warn('No screenshots were produced.');

            return self::FAILURE;
        }

        $rows = [];

        foreach ($files as $file) {
            $name = basename($file);
            $key  = "{$prefix}/{$name}";

            Storage::disk($disk)->put($key, (string) File::get($file));
            $rows[] = [$name, "{$disk}:{$key}", number_format(File::size($file) / 1024, 1).' KB'];

            if (! $this->option('keep-local')) {
                File::delete($file);
            }
        }

        $this->table(['Screenshot', 'Stored at', 'Size'], $rows);
        $this->info(count($rows).' screenshot(s) uploaded to the ['.$disk.'] disk.');

        return self::SUCCESS;
    }
}
