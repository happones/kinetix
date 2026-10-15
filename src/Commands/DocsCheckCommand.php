<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Happones\Kinetix\Docs\DocsManager;
use Happones\Kinetix\Docs\OpenApi\OpenApiDocument;
use Illuminate\Console\Command;
use RuntimeException;

class DocsCheckCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'kinetix:docs-check {--strict : Also fail on untranslated pages}';

    /**
     * @var string
     */
    protected $description = 'Check the docs: links to pages and headings that don\'t exist, and pages each language is missing';

    public function handle(DocsManager $docs): int
    {
        $prefix  = '/'.trim((string) config('kinetix.docs.routes.prefix', 'docs'), '/').'/';
        $broken  = [];
        $missing = [];

        foreach ($docs->locales() as $locale) {
            // Every page's URL with the heading ids it carries.
            $targets = [];
            $pages   = [];

            foreach ($docs->sections($locale) as $section) {
                foreach ($section->pages as $page) {
                    $html = $docs->render($page)->html;
                    $url  = $docs->url($section->key, $page->slug);

                    preg_match_all('/\sid="([^"]+)"/', $html, $ids);
                    $targets[$url] = array_flip($ids[1]);
                    $pages[]       = [$page, $url, $html];

                    if ($page->fallback) {
                        $missing[$locale][] = ltrim(substr($page->path, strlen($docs->path())), '/');
                    }
                }
            }

            foreach ($pages as [$page, $url, $html]) {
                // A fallback page is checked in its own language.
                if ($page->fallback) {
                    continue;
                }

                preg_match_all('/href="([^"]+)"/', $html, $links);

                foreach ($links[1] as $href) {
                    $href = html_entity_decode($href);

                    if (str_starts_with($href, '#')) {
                        $path     = $url;
                        $fragment = substr($href, 1);
                    } elseif (str_starts_with($href, $prefix)) {
                        $path     = strtok($href, '#') ?: $href;
                        $fragment = str_contains($href, '#') ? substr($href, strpos($href, '#') + 1) : '';
                    } else {
                        continue;
                    }

                    if (! isset($targets[$path]) || ($fragment !== '' && ! isset($targets[$path][$fragment]))) {
                        $broken[] = [$locale, ltrim(substr($page->path, strlen($docs->path())), '/'), $href];
                    }
                }
            }
        }

        // A document that can't be read leaves its section empty without a
        // word on the page: say why here.
        $unreadable = [];

        foreach ($docs->openApiSections() as $section => $spec) {
            try {
                OpenApiDocument::fromFile($spec);
            } catch (RuntimeException $exception) {
                $unreadable[] = [$section, $exception->getMessage()];
            }
        }

        if ($unreadable !== []) {
            $this->error(count($unreadable).' OpenAPI document(s) could not be read:');
            $this->table(['Section', 'Problem'], $unreadable);
        }

        if ($broken !== []) {
            $this->error(count($broken).' broken link(s):');
            $this->table(['Language', 'Page', 'Link'], $broken);
        }

        foreach ($missing as $locale => $files) {
            $this->warn("[{$locale}] ".count($files).' page(s) not translated yet, served in '.$docs->fallbackLocale().':');

            foreach ($files as $file) {
                $this->line("  {$file}");
            }
        }

        if ($broken === [] && $missing === [] && $unreadable === []) {
            $this->info('The docs check out: every link lands, every page is translated.');
        }

        return $broken !== [] || $unreadable !== [] || ($this->option('strict') && $missing !== []) ? self::FAILURE : self::SUCCESS;
    }
}
