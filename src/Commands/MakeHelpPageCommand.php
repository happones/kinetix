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
                            {--force : Overwrite the pages if they already exist}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scaffold the Help Center pages (index + article) and a sample markdown article';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
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
