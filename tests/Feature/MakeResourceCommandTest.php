<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

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
        $create = File::get($createPath);
        $edit   = File::get($editPath);
        $this->assertStringContainsString('Create Post', $create);
        $this->assertStringContainsString('Edit Post', $edit);

        // Submit/cancel go through the shared KinetixButton so the scaffold
        // gets the same pending behaviour (spinner + disabled) as actions.
        $this->assertStringContainsString('<KinetixButton', $create);
        $this->assertStringContainsString(':loading="saving"', $create);
        $this->assertStringContainsString('<KinetixButton', $edit);
        $this->assertStringContainsString(':loading="saving"', $edit);

        // Relation managers are wired into Edit/Show (auto-tabs when >1).
        $show = File::get($showPath);
        $this->assertStringContainsString('<KinetixRelationManagers', $edit);
        $this->assertStringContainsString('<KinetixRelationManagers', $show);

        // Record resolution goes through the resource's SCOPED query — never
        // implicit route-model binding, which would fetch by id alone and let
        // a team-prefixed URL render another team's record.
        $controller = File::get(app_path('Http/Controllers/Kinetix/PostController.php'));
        $this->assertStringContainsString('PostResource::getEloquentQuery()->findOrFail($record)', $controller);
        $this->assertStringNotContainsString('public function edit(Post $record)', $controller);
        $this->assertStringContainsString("relationManagersFor('edit', \$record)", $controller);
        $this->assertStringContainsString("relationManagersFor('view', \$record)", $controller);

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
        // Create has no record, so it must be gated explicitly against the class.
        $this->assertStringContainsString("CreateAction::make()->authorize('create', Post::class)->modal('create')", $resource);
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
        // Wrapper carries min-w-0 so a wide table scrolls locally instead of
        // overflowing the viewport inside a flex layout.
        $this->assertStringContainsString('flex h-full min-w-0 flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4', $index);
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
        // The tenant comes from KinetixTeams — reading `$user->currentTeam`
        // ignores the {current_team} segment and skips the membership check.
        $this->assertStringContainsString("where('team_id', KinetixTeams::currentTeamKey())", $resource);
        $this->assertStringContainsString("\$data['team_id'] = KinetixTeams::currentTeamKey();", $resource);
        $this->assertStringContainsString('use Happones\\Kinetix\\Support\\KinetixTeams;', $resource);
        $this->assertStringNotContainsString('currentTeam->id', $resource);

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
        $this->assertStringContainsString("CreateAction::make()->authorize('create', Post::class)->route('posts.create')", $resource);

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
        // Record resolution goes through the resource's SCOPED query, never
        // implicit route-model binding (team-prefixed URLs must 404 foreign ids).
        $this->assertStringContainsString('use Happones\Kinetix\Infolists\Infolist;', $controller);
        $this->assertStringContainsString('public function show(string $record)', $controller);
        $this->assertStringContainsString('PostResource::getEloquentQuery()->findOrFail($record)', $controller);
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

    public function test_team_scoping_lives_only_in_the_resource_not_the_controller(): void
    {
        $this->artisan('kinetix:make-resource', ['name' => 'Post', '--team' => true])
            ->assertSuccessful();

        // The resource's getEloquentQuery() is the SINGLE scoping point; the
        // controller re-implementing the scope inline would silently diverge
        // from what show/edit/update/destroy resolve.
        $controller = File::get(app_path('Http/Controllers/Kinetix/PostController.php'));
        $this->assertStringContainsString('public function index()', $controller);
        $this->assertStringContainsString('$query = PostResource::getEloquentQuery();', $controller);
        $this->assertStringNotContainsString("where('team_id'", $controller);
        $this->assertStringNotContainsString('KinetixTeams', $controller);
        $this->assertStringNotContainsString('currentTeam->id', $controller);

        // Under Route::prefix('{current_team}') every record route carries TWO
        // required params, injected POSITIONALLY — without a leading
        // $current_team argument the team segment lands in $record and
        // findOrFail('{team}') 404s.
        $this->assertStringContainsString('public function show(string $current_team, string $record)', $controller);
        $this->assertStringContainsString('public function edit(string $current_team, string $record)', $controller);
        $this->assertStringContainsString('public function update(Request $request, string $current_team, string $record)', $controller);
        $this->assertStringContainsString('public function destroy(string $current_team, string $record)', $controller);

        // Writes flow through the resource's save hook (which stamps team_id on
        // create and strips it on edit) instead of an inline array_merge.
        $this->assertStringContainsString("Post::create(PostResource::mutateFormDataBeforeSave(\$form->getState(\$request->all()), 'create'))", $controller);
        $this->assertStringContainsString("\$record->update(PostResource::mutateFormDataBeforeSave(\$form->getState(\$request->all()), 'edit', \$record))", $controller);

        $resource = File::get(app_path('Kinetix/Resources/PostResource.php'));
        $this->assertStringContainsString("where('team_id', KinetixTeams::currentTeamKey())", $resource);
        // A submitted team_id must never move the record to another team.
        $this->assertStringContainsString("unset(\$data['team_id']);", $resource);

        File::deleteDirectory(resource_path('js/pages/Kinetix/Posts'));
        File::deleteDirectory(app_path('Kinetix'));
        File::delete(app_path('Http/Controllers/Kinetix/PostController.php'));
    }

    public function test_controller_enforces_the_model_policy_when_one_exists(): void
    {
        $this->artisan('kinetix:make-resource', ['name' => 'Post', '--soft-deletes' => true])
            ->assertSuccessful();

        $controller = File::get(app_path('Http/Controllers/Kinetix/PostController.php'));

        // Policy-if-exists on EVERY endpoint — the same contract the built-in
        // Kinetix surfaces (record modals, table writes) enforce.
        $this->assertStringContainsString('protected function authorizeAction(string $ability, mixed $target): void', $controller);
        $this->assertStringContainsString('Gate::getPolicyFor(Post::class)', $controller);
        $this->assertStringContainsString("\$this->authorizeAction('viewAny', Post::class);", $controller);
        $this->assertStringContainsString("\$this->authorizeAction('create', Post::class);", $controller);
        $this->assertStringContainsString("\$this->authorizeAction('view', \$record);", $controller);
        $this->assertStringContainsString("\$this->authorizeAction('update', \$record);", $controller);
        $this->assertStringContainsString("\$this->authorizeAction('delete', \$record);", $controller);
        $this->assertStringContainsString("\$this->authorizeAction('restore', \$record);", $controller);
        $this->assertStringContainsString("\$this->authorizeAction('forceDelete', \$record);", $controller);

        // Simple mode gets the same viewAny gate on its single endpoint.
        File::delete(app_path('Http/Controllers/Kinetix/PostController.php'));
        File::deleteDirectory(app_path('Kinetix'));
        File::deleteDirectory(resource_path('js/pages/Kinetix/Posts'));

        $this->artisan('kinetix:make-resource', ['name' => 'Post', '--simple' => true, '--force' => true])
            ->assertSuccessful();

        $simple = File::get(app_path('Http/Controllers/Kinetix/PostController.php'));
        $this->assertStringContainsString("\$this->authorizeAction('viewAny', Post::class);", $simple);

        File::deleteDirectory(resource_path('js/pages/Kinetix/Posts'));
        File::deleteDirectory(app_path('Kinetix'));
        File::delete(app_path('Http/Controllers/Kinetix/PostController.php'));
    }

    public function test_resource_registers_its_permission_feature(): void
    {
        $this->artisan('kinetix:make-resource', ['name' => 'Post'])->assertSuccessful();

        // Without this hook the resource is discovered but registers ZERO
        // abilities — the role matrix would never show it.
        $resource = File::get(app_path('Kinetix/Resources/PostResource.php'));
        $this->assertStringContainsString('public static function permissionFeature(): ?string', $resource);
        $this->assertStringContainsString("return 'posts';", $resource);

        File::deleteDirectory(resource_path('js/pages/Kinetix/Posts'));
        File::deleteDirectory(app_path('Kinetix'));
        File::delete(app_path('Http/Controllers/Kinetix/PostController.php'));
    }

    public function test_soft_deletes_wires_the_trashed_filter_and_row_actions(): void
    {
        $this->artisan('kinetix:make-resource', ['name' => 'Post', '--soft-deletes' => true])
            ->assertSuccessful();

        // The TrashedFilter drives trashed visibility (blank = active only);
        // Restore/ForceDelete appear per row only when the record is trashed —
        // so the restore/force-delete routes are reachable from the UI.
        $resource = File::get(app_path('Kinetix/Resources/PostResource.php'));
        $this->assertStringContainsString('TrashedFilter::make(),', $resource);
        $this->assertStringContainsString("RestoreAction::make()->route('posts.restore', method: 'post')", $resource);
        $this->assertStringContainsString("ForceDeleteAction::make()->route('posts.force-delete', method: 'delete')", $resource);

        // The controller must NOT blanket-apply withTrashed on the index — the
        // filter owns it (deleted rows hidden by default, Filament parity).
        $controller = File::get(app_path('Http/Controllers/Kinetix/PostController.php'));
        $this->assertStringNotContainsString('$query = $query->withTrashed();', $controller);

        // Team-safe redirects: route('posts.index') throws under a
        // team-prefixed group (missing {current_team}); getUrl() fills it.
        $this->assertStringContainsString("redirect(PostResource::getUrl('index'))", $controller);
        $this->assertStringNotContainsString("redirect()->route('posts.index')", $controller);

        File::deleteDirectory(resource_path('js/pages/Kinetix/Posts'));
        File::deleteDirectory(app_path('Kinetix'));
        File::delete(app_path('Http/Controllers/Kinetix/PostController.php'));
    }

    public function test_generate_excludes_server_owned_and_secret_columns(): void
    {
        Schema::create('posts', function ($table) {
            $table->id();
            $table->string('title');
            $table->string('password');
            $table->string('api_token');
            $table->string('webhook_secret');
            $table->unsignedBigInteger('team_id');
            $table->integer('sort_order');
            $table->timestamps();
        });

        $this->artisan('kinetix:make-resource', ['name' => 'Post', '--generate' => true])
            ->assertSuccessful();

        $resource = File::get(app_path('Kinetix/Resources/PostResource.php'));

        $this->assertStringContainsString("TextInput::make('title')", $resource);
        // team_id is server-owned (a form field would let a submit reassign the
        // record); sort_order belongs to reordering; secrets never render.
        foreach (['team_id', 'sort_order', 'password', 'api_token', 'webhook_secret'] as $excluded) {
            $this->assertStringNotContainsString("'{$excluded}'", $resource);
        }

        Schema::drop('posts');
        File::deleteDirectory(resource_path('js/pages/Kinetix/Posts'));
        File::deleteDirectory(app_path('Kinetix'));
        File::delete(app_path('Http/Controllers/Kinetix/PostController.php'));
    }

    public function test_existing_files_are_not_overwritten_without_force(): void
    {
        $resourcePath = app_path('Kinetix/Resources/PostResource.php');
        File::ensureDirectoryExists(dirname($resourcePath));
        File::put($resourcePath, '<?php // customized');

        $this->artisan('kinetix:make-resource', ['name' => 'Post'])->assertSuccessful();
        $this->assertSame('<?php // customized', File::get($resourcePath));

        $this->artisan('kinetix:make-resource', ['name' => 'Post', '--force' => true])->assertSuccessful();
        $this->assertStringContainsString('class PostResource extends Resource', File::get($resourcePath));

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
        // Single-param routes → single-param signatures.
        $this->assertStringContainsString('public function show(string $record)', $controller);
        $this->assertStringNotContainsString('$current_team', $controller);

        File::deleteDirectory(resource_path('js/pages/Kinetix/Posts'));
        File::deleteDirectory(app_path('Kinetix'));
        File::delete(app_path('Http/Controllers/Kinetix/PostController.php'));
    }

    public function test_full_resource_pages_submit_via_server_resolved_urls(): void
    {
        $this->artisan('kinetix:make-resource', ['name' => 'Post'])->assertSuccessful();

        // The controller resolves every URL the pages need (Resource::getUrl()
        // fills the `{current_team}` segment and the record's route key).
        $controller = File::get(app_path('Http/Controllers/Kinetix/PostController.php'));
        $this->assertStringContainsString("'storeUrl' => PostResource::getUrl('store')", $controller);
        $this->assertStringContainsString("'updateUrl' => PostResource::getUrl('update', \$record)", $controller);
        $this->assertStringContainsString("'cancelUrl' => PostResource::getUrl('index')", $controller);

        $create = File::get(resource_path('js/pages/Kinetix/Posts/Create.vue'));
        $edit   = File::get(resource_path('js/pages/Kinetix/Posts/Edit.vue'));

        $this->assertStringContainsString('router.post(props.storeUrl', $create);
        $this->assertStringContainsString('router.put(props.updateUrl', $edit);

        // Both pages cancel through the server-resolved index URL, and neither
        // hardcodes a '/posts' URL that would break under a team prefix.
        foreach ([$create, $edit] as $pageSource) {
            $this->assertStringContainsString('@click="handleCancel"', $pageSource);
            $this->assertStringContainsString('router.get(props.cancelUrl)', $pageSource);
            $this->assertStringNotContainsString("'/posts'", $pageSource);
            $this->assertStringNotContainsString('`/posts/', $pageSource);
        }

        // updateUrl replaces the old recordId prop entirely.
        $this->assertStringNotContainsString('recordId', $edit);

        File::deleteDirectory(resource_path('js/pages/Kinetix/Posts'));
        File::deleteDirectory(app_path('Kinetix'));
        File::delete(app_path('Http/Controllers/Kinetix/PostController.php'));
    }

    public function test_team_resource_pages_match_the_non_team_pages(): void
    {
        // Pages are team-agnostic by design: the server resolves the URLs, so
        // the same Vue output must be generated with and without --team.
        $this->artisan('kinetix:make-resource', ['name' => 'Post'])->assertSuccessful();
        $create = File::get(resource_path('js/pages/Kinetix/Posts/Create.vue'));
        $edit   = File::get(resource_path('js/pages/Kinetix/Posts/Edit.vue'));

        File::deleteDirectory(resource_path('js/pages/Kinetix/Posts'));
        File::deleteDirectory(app_path('Kinetix'));
        File::delete(app_path('Http/Controllers/Kinetix/PostController.php'));

        $this->artisan('kinetix:make-resource', ['name' => 'Post', '--team' => true])->assertSuccessful();

        $this->assertSame($create, File::get(resource_path('js/pages/Kinetix/Posts/Create.vue')));
        $this->assertSame($edit, File::get(resource_path('js/pages/Kinetix/Posts/Edit.vue')));

        // The team controller still resolves URLs through the resource.
        $controller = File::get(app_path('Http/Controllers/Kinetix/PostController.php'));
        $this->assertStringContainsString("PostResource::getUrl('store')", $controller);

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
