<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Docs\DocsManager;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

class DocsPlaceholders
{
    /**
     * @return array<string, string>
     */
    public static function resolve(Request $request): array
    {
        return ['team' => '<acme & co>', 'api_url' => 'https://api.example.test/v1'];
    }
}

class DocsTest extends TestCase
{
    private string $docsPath;

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->docsPath = sys_get_temp_dir().'/kinetix-docs-test-'.uniqid();

        $app['config']->set('kinetix.docs.enabled', true);
        $app['config']->set('kinetix.docs.path', $this->docsPath);
        $app['config']->set('kinetix.docs.placeholders', [DocsPlaceholders::class, 'resolve']);
        $app['config']->set('cache.default', 'array');

        $this->writeDocs();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->docsPath);

        parent::tearDown();
    }

    private function write(string $file, string $markdown): void
    {
        File::ensureDirectoryExists(dirname($this->docsPath.'/'.$file));
        File::put($this->docsPath.'/'.$file, $markdown);
    }

    private function writeDocs(): void
    {
        $this->write('en/guide/index.md', "---\nsection: Guide\ntitle: Overview\norder: 1\ndescription: Start here.\n---\n# Overview\n\nWelcome to {{ app_name }}.");
        $this->write('en/guide/01-install.md', "---\ntitle: Install\ngroup: Basics\ndescription: Get {{ app_name }} running for {{ team }}.\n---\n## Requirements\n\nTeam: {{ team }}. See [configuration](configure.md#env) and [auth](../api/auth.md). Unknown: {{ nope }}.\n\n```bash\ncurl {{ api_url }}/ping\n```");
        $this->write('en/guide/02-configure.md', "---\ntitle: Configure\ngroup: Basics\n---\n## Env\n\nSet the env.");
        $this->write('en/guide/03-deploy.md', "---\ntitle: Deploy\ngroup: Production\n---\nShip it.");
        $this->write('en/guide/_draft.md', "---\ntitle: Draft\n---\nNot yet.");
        $this->write('en/api/auth.md', "# Authentication\n\nTokens.");
        $this->write('es/guide/01-install.md', "---\ntitle: Instalación\ngroup: Básicos\n---\n## Requisitos\n\nEquipo: {{ team }}.");
    }

    private function inertia(string $uri): TestResponse
    {
        return $this->withHeader('X-Inertia', 'true')->withHeader('X-Inertia-Version', '1')->get($uri);
    }

    public function test_sections_are_ordered_by_their_index_and_titled_by_it(): void
    {
        $sections = app(DocsManager::class)->sections('en');

        $this->assertSame(['guide', 'api'], array_keys($sections));
        $this->assertSame('Guide', $sections['guide']->title);
        $this->assertSame('Api', $sections['api']->title);
        $this->assertSame(['', 'install', 'configure', 'deploy'], array_map(fn ($page) => $page->slug, $sections['guide']->pages));
    }

    public function test_an_untranslated_page_falls_back_marked_and_joins_its_translated_group(): void
    {
        $pages                           = app(DocsManager::class)->section('guide', 'es')->pages;
        [$landing, $install, $configure] = $pages;

        $this->assertSame(['Instalación', 'es', false], [$install->title, $install->locale, $install->fallback]);
        $this->assertSame(['Configure', 'en', true], [$configure->title, $configure->locale, $configure->fallback]);
        $this->assertSame('Básicos', $configure->group);
        $this->assertTrue($landing->fallback);
    }

    public function test_the_language_resolves_to_its_base_then_the_fallback(): void
    {
        $docs = app(DocsManager::class);

        $this->assertSame('es', $docs->locale('es_MX'));
        $this->assertSame('es', $docs->locale('es-AR'));
        $this->assertSame('en', $docs->locale('pt_BR'));
    }

    public function test_the_index_opens_the_first_section_and_a_section_without_landing_its_first_page(): void
    {
        $this->get('/docs')->assertRedirect('/docs/guide');
        $this->get('/docs/api')->assertRedirect('/docs/api/auth');
    }

    public function test_a_page_renders_into_the_hosts_view_with_navigation_and_neighbours(): void
    {
        $response = $this->inertia('/docs/guide/configure')->assertOk();

        $response->assertJsonPath('component', 'Kinetix/Docs');
        $response->assertJsonPath('props.docs.section', ['key' => 'guide', 'title' => 'Guide']);
        $response->assertJsonPath('props.docs.sections.0', ['key' => 'guide', 'title' => 'Guide', 'url' => '/docs/guide', 'active' => true]);
        $response->assertJsonPath('props.docs.navigation.0.title', '');
        $response->assertJsonPath('props.docs.navigation.1.title', 'Basics');
        $response->assertJsonPath('props.docs.navigation.1.pages.1', ['title' => 'Configure', 'url' => '/docs/guide/configure', 'active' => true]);
        $response->assertJsonPath('props.docs.navigation.2.title', 'Production');
        $response->assertJsonPath('props.docs.previous', ['title' => 'Install', 'url' => '/docs/guide/install']);
        $response->assertJsonPath('props.docs.next', ['title' => 'Deploy', 'url' => '/docs/guide/deploy']);
        $response->assertJsonPath('props.docs.page.toc', [['level' => 2, 'id' => 'env', 'text' => 'Env']]);
        $response->assertJsonPath('props.docs.meta.title', 'Configure · Guide');
        $response->assertJsonPath('props.docs.searchUrl', '/docs/search.json');
    }

    public function test_placeholders_are_filled_escaped_and_md_links_point_at_pages(): void
    {
        $html = $this->inertia('/docs/guide/install')->json('props.docs.page.html');

        $this->assertStringContainsString('Team: &lt;acme &amp; co&gt;.', $html);
        $this->assertStringContainsString('Unknown: {{ nope }}.', $html);
        $this->assertStringContainsString('https://api.example.test/v1', $html);
        $this->assertStringNotContainsString('kxph__', $html);
        $this->assertStringContainsString('href="/docs/guide/configure#env"', $html);
        $this->assertStringContainsString('href="/docs/api/auth"', $html);
    }

    public function test_front_matter_placeholders_are_filled_raw_for_the_page_to_escape(): void
    {
        $this->inertia('/docs/guide/install')
            ->assertJsonPath('props.docs.page.description', 'Get '.config('app.name').' running for <acme & co>.')
            ->assertJsonPath('props.docs.meta.description', 'Get '.config('app.name').' running for <acme & co>.');
    }

    public function test_the_landing_page_renders_at_the_section_url(): void
    {
        $response = $this->inertia('/docs/guide')->assertOk();

        $response->assertJsonPath('props.docs.page.slug', '');
        $response->assertJsonPath('props.docs.meta.title', 'Guide');
        $response->assertJsonPath('props.docs.previous', null);
        $this->assertStringContainsString('Welcome to '.config('app.name'), $response->json('props.docs.page.html'));
    }

    public function test_the_readers_language_serves_the_translation_and_marks_a_fallback(): void
    {
        app()->setLocale('es');

        $this->inertia('/docs/guide/install')
            ->assertJsonPath('props.docs.page.title', 'Instalación')
            ->assertJsonPath('props.docs.page.fallback', false);

        $this->inertia('/docs/guide/configure')
            ->assertJsonPath('props.docs.page.locale', 'en')
            ->assertJsonPath('props.docs.page.fallback', true);
    }

    public function test_missing_pages_drafts_and_sections_are_not_found(): void
    {
        $this->get('/docs/guide/nope')->assertNotFound();
        $this->get('/docs/guide/_draft')->assertNotFound();
        $this->get('/docs/guide/draft')->assertNotFound();
        $this->get('/docs/nope')->assertNotFound();
    }

    public function test_search_lists_every_page_with_its_headings_and_raw_text(): void
    {
        $data = $this->getJson('/docs/search.json')->assertOk()->assertHeader('Content-Language', 'en')->json('data');

        $this->assertCount(5, $data);
        $install = collect($data)->firstWhere('slug', 'install');
        $this->assertSame('/docs/guide/install', $install['url']);
        $this->assertSame('Guide', $install['sectionTitle']);
        $this->assertSame([['id' => 'requirements', 'text' => 'Requirements']], $install['headings']);
        $this->assertStringContainsString('Team: <acme & co>.', $install['text']);
    }

    public function test_an_edited_page_shows_up_on_the_next_request(): void
    {
        $this->inertia('/docs/guide/deploy')->assertJsonPath('props.docs.page.title', 'Deploy');

        $this->write('en/guide/03-deploy.md', "---\ntitle: Ship\n---\nNew body.");
        touch($this->docsPath.'/en/guide/03-deploy.md', time() + 10);
        $this->app->forgetScopedInstances();

        $response = $this->inertia('/docs/guide/deploy');
        $response->assertJsonPath('props.docs.page.title', 'Ship');
        $this->assertStringContainsString('New body.', $response->json('props.docs.page.html'));
    }

    public function test_an_edit_link_points_at_the_file(): void
    {
        config()->set('kinetix.docs.edit_url', 'https://code.example/edit/main/resources/docs/{path}');

        $this->inertia('/docs/guide/install')
            ->assertJsonPath('props.docs.page.editUrl', 'https://code.example/edit/main/resources/docs/en/guide/01-install.md');
    }

    public function test_the_check_reports_broken_links_and_untranslated_pages(): void
    {
        $this->artisan('kinetix:docs-check')
            ->expectsOutputToContain('[es] 4 page(s) not translated yet')
            ->assertSuccessful();

        $this->artisan('kinetix:docs-check', ['--strict' => true])->assertFailed();

        $this->write('en/api/auth.md', "# Authentication\n\nSee [nowhere](missing.md), [a heading](../guide/configure.md#nope), [fine](../guide/configure.md#env) and [here](#authentication).");
        touch($this->docsPath.'/en/api/auth.md', time() + 10);
        $this->app->forgetScopedInstances();

        $this->artisan('kinetix:docs-check')
            ->expectsOutputToContain('2 broken link(s)')
            ->assertFailed();
    }
}
