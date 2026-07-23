<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;

class MakeHelpPageCommandTest extends TestCase
{
    private string $helpPath;

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->helpPath = sys_get_temp_dir().'/kinetix-help-scaffold-'.uniqid();
        $app['config']->set('kinetix.help.path', $this->helpPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(resource_path('js/pages/Kinetix/Help'));
        File::deleteDirectory($this->helpPath);

        parent::tearDown();
    }

    public function test_scaffolds_the_help_pages_and_a_sample_article(): void
    {
        $this->artisan('kinetix:make-help-page')->assertSuccessful();

        $index = File::get(resource_path('js/pages/Kinetix/Help/Index.vue'));
        $show  = File::get(resource_path('js/pages/Kinetix/Help/Show.vue'));

        $this->assertStringContainsString('<KinetixHelpCenter />', $index);
        $this->assertStringContainsString('<KinetixHelpArticle :slug="slug" />', $show);
        $this->assertStringContainsString('flex h-full min-w-0 flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4', $index);

        // The sample article demonstrates front matter + a gated block.
        $sample = File::get("{$this->helpPath}/01-getting-started.md");
        $this->assertStringContainsString('title: Getting started', $sample);
        $this->assertStringContainsString('<!-- kinetix:can products.view -->', $sample);
        $this->assertStringContainsString('screenshots/dashboard.png', $sample);
    }

    public function test_does_not_overwrite_articles_or_pages_without_force(): void
    {
        File::ensureDirectoryExists($this->helpPath);
        File::put("{$this->helpPath}/01-mine.md", '# Mine');

        $this->artisan('kinetix:make-help-page')->assertSuccessful();

        // Existing articles are left alone (no sample seeded next to them).
        $this->assertFileDoesNotExist("{$this->helpPath}/01-getting-started.md");

        File::put(resource_path('js/pages/Kinetix/Help/Index.vue'), 'customized');
        $this->artisan('kinetix:make-help-page')->assertFailed();
        $this->assertSame('customized', File::get(resource_path('js/pages/Kinetix/Help/Index.vue')));

        $this->artisan('kinetix:make-help-page', ['--force' => true])->assertSuccessful();
        $this->assertStringContainsString('KinetixHelpCenter', File::get(resource_path('js/pages/Kinetix/Help/Index.vue')));
    }
}
