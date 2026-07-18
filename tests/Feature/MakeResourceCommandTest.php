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

        // The controller is a thin, index-only page: it just renders the
        // resource's table over the scoped query. No action wiring, no
        // store/update/destroy (CRUD is Kinetix-owned via the modals).
        $this->assertStringContainsString('PostResource::getEloquentQuery()', $controller);
        $this->assertStringContainsString('PostResource::table(Table::make($query))->toArray()', $controller);
        $this->assertStringNotContainsString('public function store(', $controller);
        $this->assertStringNotContainsString('public function update(', $controller);
        $this->assertStringNotContainsString('public function destroy(', $controller);

        // Actions + modals live on the RESOURCE's table() (single source of truth),
        // and the row actions are grouped into a shadcn-style dropdown.
        $resource = File::get(app_path('Kinetix/Resources/PostResource.php'));
        $this->assertStringContainsString('->recordModals(static::class)', $resource);
        $this->assertStringContainsString('ActionGroup::make([', $resource);
        $this->assertStringContainsString("CreateAction::make()->modal('create')", $resource);
        $this->assertStringContainsString("ViewAction::make()->modal('view')", $resource);
        $this->assertStringContainsString("EditAction::make()->modal('edit')", $resource);
        $this->assertStringContainsString("DeleteAction::make()->modal('delete')", $resource);
        // The Resource ships an infolist() so the View modal has content.
        $this->assertStringContainsString('public static function infolist(Infolist $infolist): Infolist', $resource);

        // The generated PHP must be syntactically valid.
        foreach ([
            app_path('Http/Controllers/Kinetix/PostController.php'),
            app_path('Kinetix/Resources/PostResource.php'),
        ] as $php) {
            exec('php -l '.escapeshellarg($php).' 2>&1', $out, $code);
            $this->assertSame(0, $code, "Generated file has a syntax error: {$php}\n".implode("\n", $out));
        }

        // The page is just <KinetixTable :table> inside the standard wrapper —
        // no modal markup / submit wiring.
        $index = File::get(resource_path('js/pages/Kinetix/Posts/Index.vue'));
        $this->assertStringContainsString('<KinetixTable :table="table" />', $index);
        $this->assertStringContainsString('flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4', $index);
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

        // Reorder is a table config, so it lives on the resource's table().
        $resource = File::get(app_path('Kinetix/Resources/PostResource.php'));
        $this->assertStringContainsString('->reorderable()', $resource);

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

    public function test_full_resource_wires_row_actions_via_route_on_the_resource(): void
    {
        $this->artisan('kinetix:make-resource', ['name' => 'Post'])->assertSuccessful();

        // Row/toolbar actions are declared on the resource's table() using
        // Action::route() (self-hiding when a route isn't registered).
        $resourcePath = app_path('Kinetix/Resources/PostResource.php');
        $resource     = File::get($resourcePath);
        $this->assertStringContainsString('ActionGroup::make([', $resource);
        $this->assertStringContainsString("ViewAction::make()->route('posts.show')", $resource);
        $this->assertStringContainsString("EditAction::make()->route('posts.edit')", $resource);
        $this->assertStringContainsString("DeleteAction::make()->route('posts.destroy', method: 'delete')", $resource);
        $this->assertStringContainsString("CreateAction::make()->route('posts.create')", $resource);

        exec('php -l '.escapeshellarg($resourcePath).' 2>&1', $out, $code);
        $this->assertSame(0, $code, "Generated full resource has a syntax error:\n".implode("\n", $out));

        // The controller index() just renders the table; no action wiring.
        $controller = File::get(app_path('Http/Controllers/Kinetix/PostController.php'));
        $this->assertStringContainsString('public function index()', $controller);
        $this->assertStringContainsString('PostResource::table(Table::make($query))->toArray()', $controller);
        $this->assertStringNotContainsString('->recordActions([', $controller);

        File::deleteDirectory(resource_path('js/pages/Kinetix/Posts'));
        File::deleteDirectory(app_path('Kinetix'));
        File::delete(app_path('Http/Controllers/Kinetix/PostController.php'));
    }

    public function test_full_resource_scaffolds_show_page_and_configurable_redirects(): void
    {
        $this->artisan('kinetix:make-resource', ['name' => 'Post'])->assertSuccessful();

        $controllerPath = app_path('Http/Controllers/Kinetix/PostController.php');
        $controller     = File::get($controllerPath);

        // A read-only show() renders the infolist + header actions (via route()).
        $this->assertStringContainsString('use Happones\Kinetix\Infolists\Infolist;', $controller);
        $this->assertStringContainsString('public function show(Post $record)', $controller);
        $this->assertStringContainsString("EditAction::make()->route('posts.edit')", $controller);

        // The per-row View action (→ show) lives on the resource table().
        $resource = File::get(app_path('Kinetix/Resources/PostResource.php'));
        $this->assertStringContainsString("ViewAction::make()->route('posts.show')", $resource);

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
