<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;

/**
 * The authoring commands behind translated manuals: a coverage report per
 * article and locale, and a scaffolder for the missing variants.
 */
class HelpCommandsTest extends TestCase
{
    private string $helpPath;

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->helpPath = sys_get_temp_dir().'/kinetix-help-commands-'.uniqid();

        $app['config']->set('kinetix.help.enabled', true);
        $app['config']->set('kinetix.help.path', $this->helpPath);
        $app['config']->set('kinetix.help.locales', ['en', 'es']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists($this->helpPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->helpPath);

        parent::tearDown();
    }

    private function article(string $filename, string $contents): void
    {
        File::put("{$this->helpPath}/{$filename}", $contents);
    }

    public function test_status_reports_coverage_and_can_fail_on_gaps(): void
    {
        $this->article('01-a.md', "# A\n\nBody.");
        $this->article('01-a.es.md', "# A es\n\nCuerpo.");
        $this->article('02-b.md', "# B\n\nBody.");

        $this->artisan('kinetix:help-status')
            ->expectsOutputToContain('01-a')
            ->expectsOutputToContain('02-b')
            ->expectsOutputToContain('3/4 translations present')
            ->assertSuccessful();

        // As a CI gate, a missing translation is a failure.
        $this->artisan('kinetix:help-status --strict')->assertFailed();
    }

    public function test_status_is_clean_when_everything_is_translated(): void
    {
        $this->article('01-a.md', '# A');
        $this->article('01-a.es.md', '# A es');

        $this->artisan('kinetix:help-status --strict')
            ->expectsOutputToContain('2/2 translations present')
            ->assertSuccessful();
    }

    public function test_make_help_page_scaffolds_a_translation_skeleton(): void
    {
        $this->article('01-a.md', <<<'MD'
---
title: Getting started
permission: help.view
---

# Getting started

Intro prose.

## Screenshots

More prose.
MD);

        $this->artisan('kinetix:make-help-page --locale=es --from=01-a')
            ->assertSuccessful();

        $variant = File::get("{$this->helpPath}/01-a.es.md");

        // Front matter is copied verbatim: permissions and ordering must not
        // drift between languages.
        $this->assertStringContainsString('permission: help.view', $variant);
        $this->assertStringContainsString('# Getting started', $variant);
        $this->assertStringContainsString('## Screenshots', $variant);
        $this->assertStringContainsString('TODO (es)', $variant);
        // Only the structure is carried over — never the untranslated prose.
        $this->assertStringNotContainsString('Intro prose.', $variant);
    }

    public function test_make_help_page_scaffolds_every_article_and_skips_existing(): void
    {
        $this->article('01-a.md', '# A');
        $this->article('02-b.md', '# B');
        $this->article('01-a.es.md', '# Ya traducido');

        $this->artisan('kinetix:make-help-page --locale=es')
            ->expectsOutputToContain('1 translation file(s) created')
            ->assertSuccessful();

        $this->assertSame('# Ya traducido', File::get("{$this->helpPath}/01-a.es.md"));
        $this->assertFileExists("{$this->helpPath}/02-b.es.md");
    }

    public function test_make_help_page_rejects_a_malformed_locale(): void
    {
        $this->article('01-a.md', '# A');

        $this->artisan('kinetix:make-help-page --locale=../etc')->assertFailed();

        $this->assertCount(1, File::glob("{$this->helpPath}/*.md"));
    }
}
