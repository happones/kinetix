<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MakeHelpPageCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kinetix:make-help-page
                            {--locale= : Scaffold a translation variant instead of the pages}
                            {--from= : The article slug to translate (with --locale; omit for every article)}
                            {--force : Overwrite the pages if they already exist}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scaffold the Help Center pages (index + article), a sample article, or a translation variant';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (is_string($this->option('locale')) && $this->option('locale') !== '') {
            return $this->scaffoldTranslations((string) $this->option('locale'));
        }

        $directory = resource_path('js/pages/Kinetix/Help');

        if (File::exists("{$directory}/Index.vue") && ! $this->option('force')) {
            $this->error('resources/js/pages/Kinetix/Help/Index.vue already exists. Use --force to overwrite.');

            return self::FAILURE;
        }

        File::ensureDirectoryExists($directory);

        // Thin mounts: the components own the data flow (Kinetix's team-aware
        // help endpoints), so the pages carry no fetching logic.
        $indexTemplate = <<<'VUE'
<script setup lang="ts">
import KinetixHelpCenter from '@/components/kinetix/KinetixHelpCenter.vue';
</script>

<template>
  <div class="flex h-full min-w-0 flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
    <KinetixHelpCenter />
  </div>
</template>
VUE;

        $showTemplate = <<<'VUE'
<script setup lang="ts">
import KinetixHelpArticle from '@/components/kinetix/KinetixHelpArticle.vue';

// The route passes the article slug (see the route suggestion printed by
// `kinetix:make-help-page`).
defineProps<{
  slug: string;
}>();
</script>

<template>
  <div class="flex h-full min-w-0 flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
    <KinetixHelpArticle :slug="slug" />
  </div>
</template>
VUE;

        File::put("{$directory}/Index.vue", $indexTemplate);
        File::put("{$directory}/Show.vue", $showTemplate);
        $this->line('Created Vue Pages: [resources/js/pages/Kinetix/Help/Index.vue, Show.vue]');

        $this->createSampleArticle();

        $this->info("\nHelp Center scaffolded successfully!");
        $this->comment('Next steps:');
        $this->line('1. Enable the module: KINETIX_HELP_ENABLED=true');
        $this->line('2. Add the page routes to your routes/web.php file:');

        $routes = [
            "Route::get('help', fn () => inertia('Kinetix/Help/Index'))->name('help.index');",
            "Route::get('help/{article}', fn (...\$params) => inertia('Kinetix/Help/Show', ['slug' => end(\$params)]))->name('help.show');",
        ];

        if (config('kinetix.teams', false)) {
            $this->line('   // Team-aware: nest under the {current_team} segment Kinetix uses.');
            $this->line("   Route::prefix('{current_team}')->group(function () {");

            foreach ($routes as $route) {
                $this->line('       '.$route);
            }

            $this->line('   });');
            $this->line('   // The Show closure receives {current_team} first — end($params) is the article.');
        } else {
            foreach ($routes as $route) {
                $this->line('   '.$route);
            }
        }

        $this->line('3. Write articles in '.$this->helpPathForDisplay().' (see the sample) and run');
        $this->line('   `php artisan kinetix:help-screenshots` to capture screenshots (requires Playwright:');
        $this->line('   npm i -D playwright && npx playwright install chromium).');

        return self::SUCCESS;
    }

    /**
     * Scaffold `{slug}.{locale}.md` variants from the base articles, keeping
     * the structure a translator needs (front matter + heading skeleton) and
     * leaving the prose to translate. Existing variants are never touched
     * without `--force`.
     */
    protected function scaffoldTranslations(string $locale): int
    {
        if (! preg_match('/^[a-z]{2}([_-][A-Za-z]{2,4})?$/', $locale)) {
            $this->error("Invalid locale [{$locale}]. Use a code like `es` or `pt_BR`.");

            return self::FAILURE;
        }

        $path  = $this->helpPathForDisplay();
        $from  = $this->option('from');
        $bases = is_string($from) && $from !== ''
            ? [$path.'/'.basename($from, '.md').'.md']
            : $this->baseArticles($path);

        if ($bases === []) {
            $this->error("No base articles found in {$path}.");

            return self::FAILURE;
        }

        $created = 0;

        foreach ($bases as $base) {
            if (! File::exists($base)) {
                $this->error("Article not found: {$base}");

                return self::FAILURE;
            }

            $target = substr($base, 0, -3).".{$locale}.md";

            if (File::exists($target) && ! $this->option('force')) {
                $this->line("Skipped (exists): {$target}");

                continue;
            }

            File::put($target, $this->translationSkeleton((string) File::get($base), $locale));
            $this->line("Created: {$target}");
            $created++;
        }

        $this->info("\n{$created} translation file(s) created for [{$locale}].");
        $this->comment('Translate the headings and prose, then check coverage with `php artisan kinetix:help-status`.');

        return self::SUCCESS;
    }

    /**
     * Base (non-variant) article files in the help directory.
     *
     * @return array<int, string>
     */
    protected function baseArticles(string $path): array
    {
        return array_values(array_filter(
            File::glob("{$path}/*.md") ?: [],
            static function (string $file): bool {
                $name = basename($file);

                return $name !== 'README.md'
                    && ! str_starts_with($name, '_')
                    && ! preg_match('/\.[a-z]{2}([_-][A-Za-z]{2,4})?\.md$/', $name);
            },
        ));
    }

    /**
     * The base article reduced to what a translator fills in: the front matter
     * (verbatim — `permission`/`order`/`icon` must not drift between locales)
     * and its headings, each followed by a TODO marker.
     */
    protected function translationSkeleton(string $markdown, string $locale): string
    {
        $frontMatter = '';

        if (preg_match('/^---\R.*?\R---\R?/s', $markdown, $matches)) {
            $frontMatter = $matches[0];
            $markdown    = (string) substr($markdown, strlen($matches[0]));
        }

        $headings = [];

        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (preg_match('/^#{1,3}\s+\S/', $line)) {
                $headings[] = trim($line)."\n\nTODO ({$locale}): translate this section.\n";
            }
        }

        if ($headings === []) {
            $headings[] = "TODO ({$locale}): translate this article.\n";
        }

        return $frontMatter."\n".implode("\n", $headings);
    }

    /**
     * Seed a sample article (front matter + gated block + screenshot embed)
     * when the help directory has no articles yet.
     */
    protected function createSampleArticle(): void
    {
        $path = config('kinetix.help.path') ?: resource_path('help');

        if (File::glob("{$path}/*.md") !== []) {
            return;
        }

        File::ensureDirectoryExists($path);

        $sample = <<<'MD'
---
title: Getting started
group: Basics
icon: book-open
order: 1
---

# Getting started

Welcome to the in-app manual. Each markdown file in this directory becomes a
help article; the `NN-` filename prefix (or the `order` front matter key)
controls the ordering, and `{slug}.{locale}.md` files provide translations.

## Screenshots

Reference generated screenshots relatively — they resolve through a
storage-backed endpoint:

![Dashboard](screenshots/dashboard.png)

## Permission-gated content

Add `permission: products.view` to the front matter to hide a whole article
from users who lack that ability, or gate a single block:

<!-- kinetix:can products.view -->
## Managing products

Only users allowed to view products can read this section.
<!-- /kinetix:can -->
MD;

        File::put("{$path}/01-getting-started.md", $sample);
        $this->line("Created sample article: [{$path}/01-getting-started.md]");
    }

    protected function helpPathForDisplay(): string
    {
        return (string) (config('kinetix.help.path') ?: resource_path('help'));
    }
}
