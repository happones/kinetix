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
        $showPath   = resource_path('js/pages/Kinetix/Posts/Show.vue');

        $this->assertFileExists($createPath);
        $this->assertFileExists($editPath);
        $this->assertFileExists($showPath);

        // Regression: $modelName was undefined in createVuePages → blank model name.
        $this->assertStringContainsString('Create Post', File::get($createPath));
        $this->assertStringContainsString('Edit Post', File::get($editPath));

        // The Show page pairs a page header (Edit/Delete actions) with the infolist.
        $show = File::get($showPath);
        $this->assertStringContainsString('KinetixPageHeader', $show);
        $this->assertStringContainsString('KinetixInfolist', $show);
        $this->assertStringContainsString(':actions="actions"', $show);

        // Clean up generated artifacts.
        File::deleteDirectory(resource_path('js/pages/Kinetix/Posts'));
        File::deleteDirectory(app_path('Kinetix'));
        File::delete(app_path('Http/Controllers/Kinetix/PostController.php'));
    }

    public function test_simple_resource_wires_kinetix_owned_modal_crud(): void
    {
        $this->artisan('kinetix:make-resource', ['name' => 'Post', '--simple' => true])
            ->assertSuccessful();

        $controller = File::get(app_path('Http/Controllers/Kinetix/PostController.php'));

        // Kinetix-owned modal CRUD: the table opts in via recordModals() and the
        // create/view/edit/delete actions open in-table modals. No per-action
        // controller methods / routes.
        $this->assertStringContainsString('use Happones\Kinetix\Actions\CreateAction;', $controller);
        $this->assertStringContainsString('use Happones\Kinetix\Actions\ViewAction;', $controller);
        $this->assertStringContainsString('use Happones\Kinetix\Actions\EditAction;', $controller);
        $this->assertStringContainsString('use Happones\Kinetix\Actions\DeleteAction;', $controller);
        $this->assertStringContainsString('->recordModals(PostResource::class)', $controller);
        $this->assertStringContainsString('PostResource::getEloquentQuery()', $controller);
        $this->assertStringContainsString("CreateAction::make()->modal('create')", $controller);
        $this->assertStringContainsString("ViewAction::make()->modal('view')", $controller);
        $this->assertStringContainsString("EditAction::make()->modal('edit')", $controller);
        $this->assertStringContainsString("DeleteAction::make()->modal('delete')", $controller);

        // The simple controller is index-only (no store/update/destroy methods).
        $this->assertStringNotContainsString('public function store(', $controller);
        $this->assertStringNotContainsString('public function update(', $controller);
        $this->assertStringNotContainsString('public function destroy(', $controller);

        // The Resource ships an infolist() so the View modal has content.
        $resource = File::get(app_path('Kinetix/Resources/PostResource.php'));
        $this->assertStringContainsString('public static function infolist(Infolist $infolist): Infolist', $resource);

        // The generated PHP must be syntactically valid.
        foreach ([
            app_path('Http/Controllers/Kinetix/PostController.php'),
            app_path('Kinetix/Resources/PostResource.php'),
        ] as $php) {
            exec('php -l '.escapeshellarg($php).' 2>&1', $out, $code);
            $this->assertSame(0, $code, "Generated file has a syntax error: {$php}\n".implode("\n", $out));
        }

        // The page is just <KinetixTable :table> — no modal markup / submit wiring.
        $index = File::get(resource_path('js/pages/Kinetix/Posts/Index.vue'));
        $this->assertStringContainsString('<KinetixTable :table="table" />', $index);
        $this->assertStringNotContainsString('addEventListener', $index);
        $this->assertStringNotContainsString('openEditModal', $index);
        $this->assertStringNotContainsString('formBlueprint', $index);

        // Simple mode does not scaffold separate Create/Edit pages.
        $this->assertFileDoesNotExist(resource_path('js/pages/Kinetix/Posts/Create.vue'));
        $this->assertFileDoesNotExist(resource_path('js/pages/Kinetix/Posts/Edit.vue'));

        File::deleteDirectory(resource_path('js/pages/Kinetix/Posts'));
        File::deleteDirectory(app_path('Kinetix'));
        File::delete(app_path('Http/Controllers/Kinetix/PostController.php'));
    }

    public function test_reorderable_option_enables_drag_reordering(): void
    {
        $this->artisan('kinetix:make-resource', ['name' => 'Post', '--simple' => true, '--reorderable' => true])
            ->assertSuccessful();

        $controller = File::get(app_path('Http/Controllers/Kinetix/PostController.php'));
        $this->assertStringContainsString('->reorderable()', $controller);

        File::deleteDirectory(resource_path('js/pages/Kinetix/Posts'));
        File::deleteDirectory(app_path('Kinetix'));
        File::delete(app_path('Http/Controllers/Kinetix/PostController.php'));
    }

    public function test_simple_team_resource_scopes_query_in_the_resource(): void
    {
        $this->artisan('kinetix:make-resource', ['name' => 'Post', '--simple' => true, '--team' => true])
            ->assertSuccessful();

        // Team scoping + team_id stamping live on the resource so the modal
        // endpoint (which uses getEloquentQuery / mutateFormDataBeforeSave) stays
        // tenant-safe.
        $resourcePath = app_path('Kinetix/Resources/PostResource.php');
        $resource     = File::get($resourcePath);
        $this->assertStringContainsString('public static function getEloquentQuery(): Builder', $resource);
        $this->assertStringContainsString("where('team_id', request()->user()->currentTeam->id)", $resource);
        $this->assertStringContainsString("\$data['team_id'] = request()->user()->currentTeam->id", $resource);

        exec('php -l '.escapeshellarg($resourcePath).' 2>&1', $out, $code);
        $this->assertSame(0, $code, "Generated team resource has a syntax error:\n".implode("\n", $out));

        File::deleteDirectory(resource_path('js/pages/Kinetix/Posts'));
        File::deleteDirectory(app_path('Kinetix'));
        File::delete(app_path('Http/Controllers/Kinetix/PostController.php'));
    }

    public function test_simple_soft_deletes_controller_is_valid_php(): void
    {
        $this->artisan('kinetix:make-resource', ['name' => 'Post', '--simple' => true, '--soft-deletes' => true])
            ->assertSuccessful();

        $controllerPath = app_path('Http/Controllers/Kinetix/PostController.php');
        $controller     = File::get($controllerPath);

        // Soft-delete controllers still get restore/forceDelete (+ their imports).
        $this->assertStringContainsString('use App\Models\Post;', $controller);
        $this->assertStringContainsString('public function restore(', $controller);
        $this->assertStringContainsString('->withTrashed()', $controller);

        exec('php -l '.escapeshellarg($controllerPath).' 2>&1', $out, $code);
        $this->assertSame(0, $code, "Generated soft-delete controller has a syntax error:\n".implode("\n", $out));

        File::deleteDirectory(resource_path('js/pages/Kinetix/Posts'));
        File::deleteDirectory(app_path('Kinetix'));
        File::delete($controllerPath);
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

    public function test_full_resource_scaffolds_show_page_and_configurable_redirects(): void
    {
        $this->artisan('kinetix:make-resource', ['name' => 'Post'])->assertSuccessful();

        $controllerPath = app_path('Http/Controllers/Kinetix/PostController.php');
        $controller     = File::get($controllerPath);

        // A read-only show() renders the infolist + header actions, and the table
        // gains a per-row View action linking to it.
        $this->assertStringContainsString('use Happones\Kinetix\Actions\ViewAction;', $controller);
        $this->assertStringContainsString('use Happones\Kinetix\Infolists\Infolist;', $controller);
        $this->assertStringContainsString('public function show(Post $record)', $controller);
        $this->assertStringContainsString("route('posts.show', \$record)", $controller);

        // Post-save destination is delegated to the resource (configurable).
        $this->assertStringContainsString('PostResource::getRedirectUrlAfterCreate($record)', $controller);
        $this->assertStringContainsString('PostResource::getRedirectUrlAfterSave($record)', $controller);
        // The created record is captured so create can redirect to it.
        $this->assertStringContainsString('$record = Post::create(', $controller);

        exec('php -l '.escapeshellarg($controllerPath).' 2>&1', $out, $code);
        $this->assertSame(0, $code, "Generated full controller has a syntax error:\n".implode("\n", $out));

        File::deleteDirectory(resource_path('js/pages/Kinetix/Posts'));
        File::deleteDirectory(app_path('Kinetix'));
        File::delete($controllerPath);
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
