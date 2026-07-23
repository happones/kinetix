<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class HelpUser extends Authenticatable
{
    protected $table = 'help_users';

    public $timestamps = false;

    protected $guarded = [];
}

class HelpTest extends TestCase
{
    private string $helpPath;

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->helpPath = sys_get_temp_dir().'/kinetix-help-test-'.uniqid();

        $app['config']->set('kinetix.help.enabled', true);
        $app['config']->set('kinetix.help.path', $this->helpPath);
        $app['config']->set('kinetix.filesystem.disk', 'local');
    }

    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists($this->helpPath);

        Schema::create('help_users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        $this->actingAs(HelpUser::create(['name' => 'Reader']));
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

    public function test_index_lists_articles_with_front_matter_metadata(): void
    {
        $this->article('02-billing.md', "# Billing\n\nInvoices and plans.");
        $this->article('01-dashboard.md', <<<'MD'
---
title: The Dashboard
group: Basics
icon: layout-dashboard
---

# Ignored heading

Widgets at a glance.
MD);
        $this->article('README.md', '# Not an article');
        $this->article('_draft.md', '# Draft');
        $this->article('01-dashboard.es.md', "# El panel\n\nWidgets.");

        $this->getJson(route('kinetix.help.index'))
            ->assertOk()
            ->assertJsonCount(2, 'articles')
            ->assertJsonPath('articles.0.slug', '01-dashboard')
            ->assertJsonPath('articles.0.title', 'The Dashboard')
            ->assertJsonPath('articles.0.group', 'Basics')
            ->assertJsonPath('articles.0.icon', 'layout-dashboard')
            ->assertJsonPath('articles.0.excerpt', 'Widgets at a glance.')
            ->assertJsonPath('articles.1.title', 'Billing');
    }

    public function test_front_matter_order_beats_filename_order(): void
    {
        $this->article('01-second.md', "---\norder: 2\n---\n# Second");
        $this->article('02-first.md', "---\norder: 1\n---\n# First");

        $this->getJson(route('kinetix.help.index'))
            ->assertJsonPath('articles.0.slug', '02-first')
            ->assertJsonPath('articles.1.slug', '01-second');
    }

    public function test_show_renders_markdown_with_prev_next(): void
    {
        $this->article('01-a.md', "# Alpha\n\nFirst article body.");
        $this->article('02-b.md', "# Bravo\n\n**Bold** body.");
        $this->article('03-c.md', "# Charlie\n\nLast.");

        $this->getJson(route('kinetix.help.show', ['slug' => '02-b']))
            ->assertOk()
            ->assertJsonPath('title', 'Bravo')
            ->assertJsonPath('prev.slug', '01-a')
            ->assertJsonPath('next.slug', '03-c')
            ->assertJson(fn ($json) => $json->where('html', fn ($html) => str_contains((string) $html, '<strong>Bold</strong>'))->etc());
    }

    public function test_unknown_article_is_404(): void
    {
        $this->getJson(route('kinetix.help.show', ['slug' => 'nope']))->assertNotFound();
    }

    public function test_locale_variant_and_regional_fallback(): void
    {
        $this->article('01-a.md', "# English\n\nBase body.");
        $this->article('01-a.pt.md', "# Português\n\nCorpo base.");

        app()->setLocale('pt_BR');
        $this->getJson(route('kinetix.help.index'))
            ->assertJsonPath('articles.0.title', 'Português');

        app()->setLocale('es');
        $this->getJson(route('kinetix.help.index'))
            ->assertJsonPath('articles.0.title', 'English');
    }

    public function test_gated_article_is_hidden_and_404s_without_the_ability(): void
    {
        Gate::define('products.view', fn ($user): bool => (bool) ($user->name === 'Manager'));

        $this->article('01-open.md', "# Open\n\nFor everyone.");
        $this->article('02-products.md', "---\npermission: products.view\n---\n# Products\n\nSecret.");

        $this->getJson(route('kinetix.help.index'))->assertJsonCount(1, 'articles');
        $this->getJson(route('kinetix.help.show', ['slug' => '02-products']))->assertNotFound();

        $this->actingAs(HelpUser::create(['name' => 'Manager']));
        $this->getJson(route('kinetix.help.index'))->assertJsonCount(2, 'articles');
        $this->getJson(route('kinetix.help.show', ['slug' => '02-products']))->assertOk();
    }

    public function test_inline_blocks_are_stripped_for_denied_users(): void
    {
        Gate::define('billing.manage', fn ($user): bool => (bool) ($user->name === 'Owner'));

        $this->article('01-a.md', <<<'MD'
# Guide

Public intro.

<!-- kinetix:can billing.manage -->
## Billing secrets

Owner-only paragraph.
<!-- /kinetix:can -->

Public outro.
MD);

        $html = $this->getJson(route('kinetix.help.show', ['slug' => '01-a']))->json('html');
        $this->assertStringContainsString('Public intro.', $html);
        $this->assertStringContainsString('Public outro.', $html);
        $this->assertStringNotContainsString('Owner-only paragraph.', $html);
        $this->assertStringNotContainsString('kinetix:can', $html);

        $this->actingAs(HelpUser::create(['name' => 'Owner']));
        $html = $this->getJson(route('kinetix.help.show', ['slug' => '01-a']))->json('html');
        $this->assertStringContainsString('Owner-only paragraph.', $html);
        $this->assertStringNotContainsString('kinetix:can', $html);
    }

    public function test_raw_html_and_unsafe_links_are_neutralized(): void
    {
        $this->article('01-a.md', "# A\n\n<script>alert(1)</script>\n\n[click](javascript:alert(1))");

        $html = $this->getJson(route('kinetix.help.show', ['slug' => '01-a']))->json('html');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_screenshot_sources_are_rewritten_to_the_streaming_route(): void
    {
        $this->article('01-a.md', "# A\n\n![Dash](screenshots/dash.png)");

        $html = $this->getJson(route('kinetix.help.show', ['slug' => '01-a']))->json('html');
        $this->assertStringContainsString('src="'.url('/_kinetix/help/screenshots').'/dash.png"', $html);
    }

    public function test_screenshots_stream_from_the_disk_with_local_fallback(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('help/screenshots/on-disk.png', 'disk-bytes');

        $this->get(route('kinetix.help.screenshot', ['file' => 'on-disk.png']))->assertOk();

        // Local fallback: committed next to the articles.
        File::ensureDirectoryExists("{$this->helpPath}/screenshots");
        File::put("{$this->helpPath}/screenshots/committed.png", 'png-bytes');
        $this->get(route('kinetix.help.screenshot', ['file' => 'committed.png']))->assertOk();

        $this->get(route('kinetix.help.screenshot', ['file' => 'missing.png']))->assertNotFound();
    }

    public function test_screenshot_route_rejects_traversal_and_non_images(): void
    {
        File::ensureDirectoryExists("{$this->helpPath}/screenshots");
        File::put("{$this->helpPath}/screenshots/real.png", 'png');

        $this->get('/_kinetix/help/screenshots/secret.env')->assertNotFound();
        $this->get('/_kinetix/help/screenshots/..%2Freal.png')->assertNotFound();
        $this->get('/_kinetix/help/screenshots/real.php')->assertNotFound();
    }

    public function test_search_matches_bodies_of_authorized_articles_only(): void
    {
        Gate::define('secrets.view', fn (): bool => false);

        $this->article('01-a.md', "# Alpha\n\nThe kanban board supports drag and drop.");
        $this->article('02-b.md', "---\npermission: secrets.view\n---\n# Hidden\n\nkanban secrets here.");

        $this->getJson(route('kinetix.help.search', ['q' => 'kanban']))
            ->assertOk()
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.slug', '01-a')
            ->assertJson(fn ($json) => $json->where('results.0.excerpt', fn ($e) => str_contains((string) $e, 'kanban'))->etc());

        // Short queries return nothing.
        $this->getJson(route('kinetix.help.search', ['q' => 'k']))->assertJsonCount(0, 'results');
    }

    public function test_metadata_cache_invalidates_when_a_file_changes(): void
    {
        config(['kinetix.help.cache.enabled' => true, 'cache.default' => 'array']);

        $this->article('01-a.md', "# Before\n\nBody.");
        $this->getJson(route('kinetix.help.index'))->assertJsonPath('articles.0.title', 'Before');

        // A different mtime produces a different fingerprint → fresh metadata.
        $this->article('01-a.md', "# After\n\nBody.");
        touch("{$this->helpPath}/01-a.md", time() + 5);
        $this->getJson(route('kinetix.help.index'))->assertJsonPath('articles.0.title', 'After');
    }
}
