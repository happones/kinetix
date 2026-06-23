<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;

class MakeResourceDummy extends Model
{
    protected $table = 'posts';
}

class MakeResourceCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The command resolves App\Models\{Name}; alias a dummy so it "exists".
        if (! class_exists('App\\Models\\Post')) {
            class_alias(MakeResourceDummy::class, 'App\\Models\\Post');
        }
    }

    public function test_generated_vue_pages_include_the_model_name(): void
    {
        $this->artisan('kinetix:make-resource', ['name' => 'Post'])->assertSuccessful();

        $createPath = resource_path('js/pages/Kinetix/Posts/Create.vue');
        $editPath   = resource_path('js/pages/Kinetix/Posts/Edit.vue');

        $this->assertFileExists($createPath);
        $this->assertFileExists($editPath);

        // Regression: $modelName was undefined in createVuePages → blank model name.
        $this->assertStringContainsString('Create Post', File::get($createPath));
        $this->assertStringContainsString('Edit Post', File::get($editPath));

        // Clean up generated artifacts.
        File::deleteDirectory(resource_path('js/pages/Kinetix/Posts'));
        File::deleteDirectory(app_path('Kinetix'));
        File::delete(app_path('Http/Controllers/Kinetix/PostController.php'));
    }

    public function test_team_option_scopes_the_controller_to_the_current_team(): void
    {
        $this->artisan('kinetix:make-resource', ['name' => 'Post', '--team' => true])
            ->assertSuccessful();

        $controller = File::get(app_path('Http/Controllers/Kinetix/PostController.php'));

        $this->assertStringContainsString('public function index(Request $request)', $controller);
        $this->assertStringContainsString("where('team_id', \$request->user()->currentTeam->id)", $controller);
        $this->assertStringContainsString("'team_id' => \$request->user()->currentTeam->id", $controller);

        File::deleteDirectory(resource_path('js/pages/Kinetix/Posts'));
        File::deleteDirectory(app_path('Kinetix'));
        File::delete(app_path('Http/Controllers/Kinetix/PostController.php'));
    }

    public function test_default_controller_is_not_team_scoped(): void
    {
        $this->artisan('kinetix:make-resource', ['name' => 'Post'])->assertSuccessful();

        $controller = File::get(app_path('Http/Controllers/Kinetix/PostController.php'));

        $this->assertStringContainsString('public function index()', $controller);
        $this->assertStringNotContainsString('currentTeam', $controller);

        File::deleteDirectory(resource_path('js/pages/Kinetix/Posts'));
        File::deleteDirectory(app_path('Kinetix'));
        File::delete(app_path('Http/Controllers/Kinetix/PostController.php'));
    }
}
