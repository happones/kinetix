<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Happones\Kinetix\Commands\Concerns\WritesGeneratedFiles;
use Happones\Kinetix\Docs\DocsManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MakeDocsPageCommand extends Command
{
    use WritesGeneratedFiles;

    /**
     * @var string
     */
    protected $signature = 'kinetix:make-docs-page {--force : Overwrite the page if it already exists}';

    /**
     * @var string
     */
    protected $description = 'Scaffold the docs page (it mounts <KinetixDocs>) and a sample section';

    public function handle(DocsManager $docs): int
    {
        $view = (string) config('kinetix.docs.view', 'Kinetix/Docs');
        $page = resource_path("js/pages/{$view}.vue");

        if (File::exists($page) && ! $this->option('force')) {
            $this->error("resources/js/pages/{$view}.vue already exists. Use --force to overwrite.");

            return self::FAILURE;
        }

        // A thin mount: DocsController hands the page everything as `docs`.
        $this->putGenerated($page, <<<'VUE'
<script setup lang="ts">
import KinetixDocs from '@/components/kinetix/KinetixDocs.vue';
import type { KinetixDocsPayload } from '@/types/kinetix';

defineProps<{
  docs: KinetixDocsPayload;
}>();
</script>

<template>
  <div class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6">
    <KinetixDocs :docs="docs" />
  </div>
</template>
VUE);
        $this->line("Created Vue page: [resources/js/pages/{$view}.vue]");

        $this->createSample($docs);

        $this->info("\nDocs scaffolded successfully!");
        $this->comment('Next steps:');
        $this->line("1. Turn the module on: 'docs' => ['enabled' => true] in config/kinetix.php");
        $this->line('   (or KINETIX_DOCS_ENABLED=true). Kinetix registers /'.trim((string) config('kinetix.docs.routes.prefix', 'docs'), '/').' and its pages.');
        $this->line('2. Wrap the page in your layout (a public one, unless the docs routes require auth).');
        $this->line('3. Write pages in '.$docs->path().'/{locale}/{section}/{page}.md.');

        return self::SUCCESS;
    }

    protected function createSample(DocsManager $docs): void
    {
        if (File::glob($docs->path().'/*/*/*.md') !== []) {
            return;
        }

        $section = $docs->path().'/'.$docs->fallbackLocale().'/guide';

        $this->putGenerated("{$section}/index.md", <<<'MD'
---
section: Guide
title: Overview
order: 1
description: What this guide covers.
---

Welcome to the docs of {{ app_name }}. Every markdown file under
`{locale}/{section}/` is a page; a section's `index.md` (this file) is its
landing page and names the section.

> [!TIP]
> Start with [Getting started](01-getting-started.md).
MD);

        $this->putGenerated("{$section}/01-getting-started.md", <<<'MD'
---
title: Getting started
group: Basics
description: Install the CLI and make your first request.
---

## Install

```bash [npm]
npm install --global acme-cli
```

```bash [yarn]
yarn global add acme-cli
```

## First request

```bash
curl {{ app_url }}/api/ping
```

The `NN-` prefix orders pages without showing in the URL; `group` puts them
under a heading in the sidebar.
MD);

        $this->line('Created a sample section: ['.$section.']');
    }
}
