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

    public function test_simple_resource_wires_modal_crud_actions(): void
    {
        $this->artisan('kinetix:make-resource', ['name' => 'Post', '--simple' => true])
            ->assertSuccessful();

        $controller = File::get(app_path('Http/Controllers/Kinetix/PostController.php'));

        // Per-row Edit/Delete actions are wired: Edit re-renders with ?edit={id},
        // Delete confirms then DELETEs — no dead code, no separate pages.
        $this->assertStringContainsString('use Happones\Kinetix\Actions\EditAction;', $controller);
        $this->assertStringContainsString('use Happones\Kinetix\Actions\DeleteAction;', $controller);
        $this->assertStringContainsString('->recordActions([', $controller);
        $this->assertStringContainsString("route('posts.index', ['edit' => \$record->getKey()])", $controller);
        $this->assertStringContainsString("route('posts.destroy', \$record)", $controller);
        $this->assertStringContainsString("'editRecord' => \$editRecord", $controller);
        // index() takes a Request (to read ?edit) even without --team.
        $this->assertStringContainsString('public function index(Request $request)', $controller);

        $index = File::get(resource_path('js/pages/Kinetix/Posts/Index.vue'));
        $this->assertStringContainsString('editRecord', $index);
        $this->assertStringContainsString('openEditModal', $index);

        // Simple mode does not scaffold separate Create/Edit pages.
        $this->assertFileDoesNotExist(resource_path('js/pages/Kinetix/Posts/Create.vue'));
        $this->assertFileDoesNotExist(resource_path('js/pages/Kinetix/Posts/Edit.vue'));

        File::deleteDirectory(resource_path('js/pages/Kinetix/Posts'));
        File::deleteDirectory(app_path('Kinetix'));
        File::delete(app_path('Http/Controllers/Kinetix/PostController.php'));
    }

    public function test_full_resource_wires_row_edit_and_delete_actions(): void
    {
        $this->artisan('kinetix:make-resource', ['name' => 'Post'])->assertSuccessful();

        $controller = File::get(app_path('Http/Controllers/Kinetix/PostController.php'));

        // The index table gets per-row Edit (navigates to the edit page) and
        // Delete (confirm → DELETE) actions.
        $this->assertStringContainsString('use Happones\Kinetix\Actions\EditAction;', $controller);
        $this->assertStringContainsString('use Happones\Kinetix\Actions\DeleteAction;', $controller);
        $this->assertStringContainsString('->recordActions([', $controller);
        $this->assertStringContainsString("route('posts.edit', \$record)", $controller);
        $this->assertStringContainsString("route('posts.destroy', \$record)", $controller);
        // Full-mode index stays parameterless (no ?edit query to read).
        $this->assertStringContainsString('public function index()', $controller);

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

    public function test_generated_controller_passes_resource_breadcrumbs(): void
    {
        $this->artisan('kinetix:make-resource', ['name' => 'Post'])->assertSuccessful();

        $controller = File::get(app_path('Http/Controllers/Kinetix/PostController.php'));

        $this->assertStringContainsString("PostResource::breadcrumbs('index')", $controller);
        $this->assertStringContainsString("PostResource::breadcrumbs('create')", $controller);
        $this->assertStringContainsString("PostResource::breadcrumbs('edit', \$record)", $controller);

        File::deleteDirectory(resource_path('js/pages/Kinetix/Posts'));
        File::deleteDirectory(app_path('Kinetix'));
        File::delete(app_path('Http/Controllers/Kinetix/PostController.php'));
    }
}
