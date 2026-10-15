<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Docs\DocsManager;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;

class MakeDocsPageCommandTest extends TestCase
{
    private string $docsPath;

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->docsPath = sys_get_temp_dir().'/kinetix-docs-scaffold-'.uniqid();
        $app['config']->set('kinetix.docs.enabled', true);
        $app['config']->set('kinetix.docs.path', $this->docsPath);
        $app['config']->set('kinetix.docs.cache.enabled', false);
    }

    protected function tearDown(): void
    {
        File::delete(resource_path('js/pages/Kinetix/Docs.vue'));
        File::deleteDirectory($this->docsPath);

        parent::tearDown();
    }

    public function test_scaffolds_the_page_and_a_sample_section_that_renders(): void
    {
        $this->artisan('kinetix:make-docs-page')->assertSuccessful();

        $page = File::get(resource_path('js/pages/Kinetix/Docs.vue'));
        $this->assertStringContainsString('<KinetixDocs :docs="docs" />', $page);
        $this->assertStringContainsString("import type { KinetixDocsPayload } from '@/types/kinetix';", $page);

        $docs    = app(DocsManager::class);
        $section = $docs->section('guide');

        $this->assertSame('Guide', $section?->title);
        $this->assertSame(['', 'getting-started'], array_map(fn ($page) => $page->slug, $section->pages));

        $html = $docs->render($section->pages[0])->html;
        $this->assertStringContainsString('href="/docs/guide/getting-started"', $html);
        $this->assertStringContainsString('Welcome to the docs of '.e((string) config('app.name')), $html);
        $this->assertStringContainsString('kx-code-group', $docs->render($section->pages[1])->html);
    }

    public function test_leaves_existing_docs_and_the_page_alone_without_force(): void
    {
        File::ensureDirectoryExists("{$this->docsPath}/en/api");
        File::put("{$this->docsPath}/en/api/auth.md", '# Auth');
        File::ensureDirectoryExists(resource_path('js/pages/Kinetix'));
        File::put(resource_path('js/pages/Kinetix/Docs.vue'), 'mine');

        $this->artisan('kinetix:make-docs-page')->assertFailed();
        $this->assertSame('mine', File::get(resource_path('js/pages/Kinetix/Docs.vue')));

        $this->artisan('kinetix:make-docs-page', ['--force' => true])->assertSuccessful();
        $this->assertDirectoryDoesNotExist("{$this->docsPath}/en/guide");
    }
}
