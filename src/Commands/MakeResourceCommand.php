<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MakeResourceCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kinetix:make-resource 
                            {name : The name of the resource (e.g. Post)} 
                            {--simple : Create a single-page resource with modals rather than separate pages}
                            {--soft-deletes : Add soft delete filters, columns, and actions}
                            {--team : Scaffold team-aware routes & queries (auto-enabled when kinetix.teams is true)}
                            {--reorderable : Enable drag-and-drop row reordering (persists to a `sort_order` integer column)}
                            {--generate : Read database table columns to automatically populate form and table schemas}
                            {--force : Overwrite existing scaffold files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new Kinetix CRUD Resource including PHP configuration, Controller, and Vue views';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = $this->argument('name');

        // Normalize name to singular StudlyCase
        $modelName     = ucfirst(Str::camel(Str::singular($name)));
        $resourceClass = "{$modelName}Resource";
        $pluralName    = Str::plural($modelName);
        $pluralSlug    = Str::kebab($pluralName);

        $simple      = $this->option('simple');
        $softDeletes = $this->option('soft-deletes');
        $generate    = $this->option('generate');
        $reorderable = (bool) $this->option('reorderable');
        // Team-aware when explicitly requested or when the package teams mode is on.
        $teams = $this->option('team') || (bool) config('kinetix.teams', false);

        // Check if model exists
        $modelClass = $this->resolveModelClass($modelName);
        if (! $modelClass) {
            $this->error("Eloquent Model App\\Models\\{$modelName} does not exist. Please create the model first.");

            return self::FAILURE;
        }

        // Generate schema lists
        [$formFields, $tableColumns, $infolistEntries] = $this->getSchemaDefinitions($modelClass, $generate, $softDeletes);

        // 1. Create PHP Resource Class
        $this->createResourceClass($modelName, $resourceClass, $formFields, $tableColumns, $infolistEntries, $teams, $simple, $reorderable, $pluralSlug, $softDeletes);

        // 2. Create Resource Controller
        $this->createController($modelName, $resourceClass, $pluralName, $pluralSlug, $simple, $softDeletes, $teams);

        // 3. Create Vue frontend pages
        $this->createVuePages($modelName, $pluralName, $pluralSlug, $simple, $softDeletes, $formFields, $tableColumns);

        $this->info("\nKinetix Resource [{$modelName}] scaffolded successfully!");
        $this->comment('Next steps:');
        $this->line('1. Register the routes in routes/web.php — inside your auth middleware');
        $this->line('   group (the controller enforces the model policy per action, but the');
        $this->line('   routes must never be public):');

        $controllerRef = "\\App\\Http\\Controllers\\Kinetix\\{$modelName}Controller::class";

        // Simple resources are single-page: create/edit/view/delete run through
        // Kinetix's own signed record endpoint (Table::recordModals()), so only
        // the index route is needed.
        $indent = $teams ? '           ' : '       ';
        $emit   = function (string $line) use ($indent): void {
            $this->line($indent.$line);
        };

        $this->line("   Route::middleware('auth')->group(function () {");

        if ($teams) {
            $this->line('       // The {current_team} prefix only namespaces URLs — row isolation');
            $this->line("       // lives in {$resourceClass}::getEloquentQuery().");
            $this->line("       Route::prefix('{current_team}')->group(function () {");
        }

        if ($simple) {
            $emit("Route::get('{$pluralSlug}', [{$controllerRef}, 'index'])->name('{$pluralSlug}.index');");
            $emit('// Create / edit / view / delete are handled by Kinetix (no extra routes).');
        } else {
            $emit("Route::resource('{$pluralSlug}', {$controllerRef});");
        }

        if ($softDeletes) {
            $emit("Route::post('{$pluralSlug}/{id}/restore', [{$controllerRef}, 'restore'])->name('{$pluralSlug}.restore');");
            $emit("Route::delete('{$pluralSlug}/{id}/force-delete', [{$controllerRef}, 'forceDelete'])->name('{$pluralSlug}.force-delete');");
        }

        if ($teams) {
            $this->line('       });');
        }

        $this->line('   });');

        $this->line('2. Create the model policy — without one, every ability check is skipped');
        $this->line('   and any authenticated user gets full CRUD:');
        $this->line("   php artisan make:policy {$modelName}Policy --model={$modelName}");
        $this->line('   With kinetix permissions enabled, DELEGATE each ability to the matrix');
        $this->line("   (never `return true;`): \$user->can('{$pluralSlug}.update') — plus your");
        $this->line('   team boundary, e.g. $user->belongsToTeam($record->team).');
        $this->line("3. The resource registers '{$pluralSlug}' CRUD abilities in the role matrix");
        $this->line('   (permissionFeature). Sync them into spatie/laravel-permission with:');
        $this->line('   php artisan kinetix:permissions:sync');

        if ($teams) {
            $this->line('4. Make sure the model has a fillable `team_id` column (indexed) — if it');
            $this->line("   isn't fillable, the create stamp is silently dropped and rows are");
            $this->line('   created with no team.');
        }

        return self::SUCCESS;
    }

    /**
     * Write a scaffold file, refusing to overwrite existing work unless --force.
     */
    protected function writeScaffoldFile(string $path, string $contents, string $label): void
    {
        if (File::exists($path) && ! $this->option('force')) {
            $this->warn("Skipped {$label} — the file already exists (use --force to overwrite).");

            return;
        }

        File::put($path, $contents);
        $this->line("Created {$label}");
    }

    /**
     * Resolve the full class name of the Eloquent model.
     */
    protected function resolveModelClass(string $modelName): ?string
    {
        $appModel = "App\\Models\\{$modelName}";
        if (class_exists($appModel)) {
            return $appModel;
        }

        $rootModel = "App\\{$modelName}";
        if (class_exists($rootModel)) {
            return $rootModel;
        }

        return null;
    }

    /**
     * Inspect database table column names and types to generate schemas.
     */
    protected function getSchemaDefinitions(string $modelClass, bool $generate, bool $softDeletes): array
    {
        $formFields      = [];
        $tableColumns    = [];
        $infolistEntries = [];

        if (! $generate) {
            $formFields[]      = "                TextInput::make('title')->required(),";
            $tableColumns[]    = "                TextColumn::make('title')->searchable()->sortable(),";
            $infolistEntries[] = "                TextEntry::make('title'),";

            return [$formFields, $tableColumns, $infolistEntries];
        }

        try {
            $model     = new $modelClass;
            $tableName = $model->getTable();

            if (method_exists(Schema::class, 'getColumns')) {
                $columns = Schema::getColumns($tableName);
            } else {
                $columnNames = Schema::getColumnListing($tableName);
                $columns     = [];
                foreach ($columnNames as $colName) {
                    $columns[] = [
                        'name'      => $colName,
                        'type_name' => 'string',
                        'nullable'  => true,
                    ];
                }
            }

            // Server-owned columns never belong in a user-editable schema:
            // `team_id` is stamped by the resource (a form field here would let
            // a submit reassign the record to another team) and `sort_order`
            // belongs to drag-and-drop reordering.
            $serverOwned = ['id', 'created_at', 'updated_at', 'deleted_at', 'team_id', 'sort_order'];
            $hidden      = $model->getHidden();

            foreach ($columns as $column) {
                $colName    = $column['name'];
                $colType    = strtolower($column['type_name'] ?? 'string');
                $isNullable = $column['nullable'] ?? true;

                if (in_array($colName, $serverOwned, true)) {
                    continue;
                }

                // Secrets stay out of forms, tables, and infolists entirely.
                if (
                    in_array($colName, $hidden, true)
                    || $colName === 'password'
                    || Str::endsWith($colName, ['_token', '_secret'])
                ) {
                    continue;
                }

                $formRule  = '';
                $tableRule = '';
                $entryRule = "                TextEntry::make('{$colName}')";

                if ($colType === 'boolean' || $colType === 'tinyint' || Str::startsWith($colName, 'is_')) {
                    $formRule  = "                Toggle::make('{$colName}')";
                    $tableRule = "                ToggleColumn::make('{$colName}')";
                    $entryRule = "                IconEntry::make('{$colName}')->boolean()";
                } elseif (Str::contains($colName, 'email')) {
                    $formRule  = "                TextInput::make('{$colName}')->email()";
                    $tableRule = "                TextColumn::make('{$colName}')";
                    $entryRule = "                TextEntry::make('{$colName}')->copyable()->icon('mail')";
                } elseif ($colType === 'text') {
                    $formRule  = "                Textarea::make('{$colName}')";
                    $tableRule = "                TextColumn::make('{$colName}')->limit(50)";
                    $entryRule = "                TextEntry::make('{$colName}')";
                } elseif (in_array($colType, ['datetime', 'timestamp', 'date'], true)) {
                    $formRule  = "                DateTimePicker::make('{$colName}')";
                    $tableRule = "                TextColumn::make('{$colName}')->dateTime()";
                    $entryRule = "                TextEntry::make('{$colName}')->dateTime()";
                } elseif (Str::contains($colType, 'int') || in_array($colType, ['decimal', 'float', 'double'], true)) {
                    $formRule  = "                TextInput::make('{$colName}')->numeric()";
                    $tableRule = "                TextColumn::make('{$colName}')->sortable()";
                    $entryRule = "                TextEntry::make('{$colName}')";
                } else {
                    $formRule  = "                TextInput::make('{$colName}')";
                    $tableRule = "                TextColumn::make('{$colName}')->searchable()->sortable()";
                    $entryRule = "                TextEntry::make('{$colName}')";
                }

                if (! $isNullable) {
                    $formRule .= '->required()';
                }

                $formFields[]      = $formRule.',';
                $tableColumns[]    = $tableRule.',';
                $infolistEntries[] = $entryRule.',';
            }

            if (empty($formFields)) {
                $formFields[]      = "                TextInput::make('title')->required(),";
                $tableColumns[]    = "                TextColumn::make('title')->searchable()->sortable(),";
                $infolistEntries[] = "                TextEntry::make('title'),";
            }
        } catch (\Exception $e) {
            // A silent fallback would make a --generate run against a missing
            // or misnamed table look successful.
            $this->warn("Could not introspect the table for --generate ({$e->getMessage()}); falling back to a placeholder `title` field.");

            $formFields[]      = "                TextInput::make('title')->required(),";
            $tableColumns[]    = "                TextColumn::make('title')->searchable()->sortable(),";
            $infolistEntries[] = "                TextEntry::make('title'),";
        }

        return [$formFields, $tableColumns, $infolistEntries];
    }

    /**
     * Generate Kinetix Resource PHP config class.
     */
    protected function createResourceClass(
        string $modelName,
        string $resourceClass,
        array $formFields,
        array $tableColumns,
        array $infolistEntries = [],
        bool $teams = false,
        bool $simple = false,
        bool $reorderable = false,
        string $pluralSlug = '',
        bool $softDeletes = false
    ): void {
        $directory = app_path('Kinetix/Resources');
        $filePath  = "{$directory}/{$resourceClass}.php";

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $formFieldsStr = implode("\n", $formFields);

        // Full mode: a Section provides the form's card surface (the
        // scaffolded Create/Edit pages render the schema with no extra
        // wrapper). Simple mode: the record MODAL is the surface, so fields
        // stay bare — a Section there would nest card-in-modal.
        if ($simple) {
            $formSchemaStr = $formFieldsStr;
            $sectionImport = '';
        } else {
            $indented      = (string) preg_replace('/^/m', '        ', $formFieldsStr);
            $formSchemaStr = "                Section::make(__('Details'))\n"
                ."                    ->schema([\n"
                .$indented."\n"
                .'                    ]),';
            $sectionImport = "\nuse Happones\\Kinetix\\Forms\\Components\\Section;";
        }

        $tableColumnsStr    = implode("\n", $tableColumns);
        $infolistEntriesStr = implode("\n", $infolistEntries !== [] ? $infolistEntries : ["                TextEntry::make('title'),"]);

        // Full mode: the Show page renders the infolist standalone, so a
        // Section (heading + two-column grid) gives it a proper card with
        // hierarchy — mirroring the form. Simple mode: the View MODAL is the
        // surface, so entries stay bare.
        if ($simple) {
            $infolistSchemaStr     = $infolistEntriesStr;
            $infolistSectionImport = '';
        } else {
            $indentedEntries   = (string) preg_replace('/^/m', '        ', $infolistEntriesStr);
            $infolistSchemaStr = "                InfolistSection::make(__('Details'))\n"
                ."                    ->columns(2)\n"
                ."                    ->schema([\n"
                .$indentedEntries."\n"
                .'                    ]),';
            $infolistSectionImport = "\nuse Happones\\Kinetix\\Infolists\\Components\\Section as InfolistSection;";
        }

        // Actions live on the table config (single source of truth). Simple mode
        // opens in-table modals; full mode navigates to the scaffolded pages via
        // Action::route() — which auto-hides a button when its route isn't
        // registered, so nothing renders as a dead link.
        $reorderableChain = $reorderable ? "\n            ->reorderable()" : '';

        // Soft deletes: the TrashedFilter drives trashed visibility (blank =
        // active only, matching the SoftDeletes global scope), and Restore /
        // ForceDelete appear per row only when the record is trashed.
        $trashedFilterChain = $softDeletes
            ? "\n            ->filters([\n                TrashedFilter::make(),\n            ])"
            : '';
        $softDeleteRowActions = $softDeletes
            ? "\n                    RestoreAction::make()->route('{$pluralSlug}.restore', method: 'post'),\n                    ForceDeleteAction::make()->route('{$pluralSlug}.force-delete', method: 'delete'),"
            : '';

        // Row actions are grouped into a shadcn-style "⋯" dropdown by default;
        // the empty group auto-hides when every child is unauthorized/unrouted.
        // View/Edit/Delete/Restore/ForceDelete check the model policy per record
        // out of the box; Create must be gated explicitly (it has no record).
        if ($simple) {
            $tableActions = <<<PHP

            ->recordModals(static::class){$reorderableChain}{$trashedFilterChain}
            ->toolbarActions([
                CreateAction::make()->authorize('create', {$modelName}::class)->modal('create'),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()->modal('view'),
                    EditAction::make()->modal('edit'),
                    DeleteAction::make()->modal('delete'),{$softDeleteRowActions}
                ]),
            ]);
PHP;
        } else {
            $tableActions = <<<PHP
{$reorderableChain}{$trashedFilterChain}
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()->route('{$pluralSlug}.show'),
                    EditAction::make()->route('{$pluralSlug}.edit'),
                    DeleteAction::make()->route('{$pluralSlug}.destroy', method: 'delete'),{$softDeleteRowActions}
                ]),
            ])
            ->toolbarActions([
                CreateAction::make()->authorize('create', {$modelName}::class)->route('{$pluralSlug}.create'),
            ]);
PHP;
        }

        // Team-aware resources scope every read/write to the current team and
        // stamp `team_id` on create, so the in-table modal endpoint stays
        // tenant-safe. Adjust the column/relation to match your schema.
        $teamHooks   = '';
        $teamImports = '';

        if ($teams) {
            $teamImports = "\nuse Happones\Kinetix\Support\KinetixTeams;\nuse Illuminate\Database\Eloquent\Builder;";
            $teamHooks   = <<<PHP


    /**
     * Scopes every read — the index table AND the in-table modal endpoint's
     * record lookup — to the active team.
     *
     * `KinetixTeams::currentTeamKey()` reads the `{current_team}` URL segment
     * (so a page served for team B never reads team A's rows) and verifies the
     * user belongs to it, falling back to their currentTeam outside a request.
     * Resolving with `\$user->currentTeam` directly would skip both.
     */
    public static function getEloquentQuery(): Builder
    {
        return {$modelName}::where('team_id', KinetixTeams::currentTeamKey());
    }

    /**
     * `team_id` is server-owned: the generator keeps it out of the form schema,
     * it is stamped here on create, and any submitted value is stripped on edit
     * — so a forged payload can never reassign the record to another team.
     */
    public static function mutateFormDataBeforeSave(array \$data, string \$operation, ?\Illuminate\Database\Eloquent\Model \$record = null): array
    {
        if (\$operation === 'create') {
            \$data['team_id'] = KinetixTeams::currentTeamKey();
        } else {
            unset(\$data['team_id']);
        }

        return \$data;
    }
PHP;
        }

        $softDeleteImports = $softDeletes
            ? "\nuse Happones\Kinetix\Actions\ForceDeleteAction;\nuse Happones\Kinetix\Actions\RestoreAction;\nuse Happones\Kinetix\Tables\Filters\TrashedFilter;"
            : '';

        // Without this hook the resource is discovered but registers ZERO
        // abilities — the role matrix would never show it.
        $permissionHook = <<<PHP


    /**
     * Registers `{$pluralSlug}` CRUD abilities (viewAny/view/create/update/delete)
     * in the Kinetix permission catalog — the role matrix picks them up
     * automatically. Return null to opt out.
     */
    public static function permissionFeature(): ?string
    {
        return '{$pluralSlug}';
    }
PHP;

        $template = <<<PHP
<?php

declare(strict_types=1);

namespace App\Kinetix\Resources;

use App\Models\\{$modelName};
use Happones\Kinetix\Actions\ActionGroup;
use Happones\Kinetix\Actions\CreateAction;
use Happones\Kinetix\Actions\DeleteAction;
use Happones\Kinetix\Actions\EditAction;
use Happones\Kinetix\Actions\ViewAction;{$softDeleteImports}
use Happones\Kinetix\Resources\Resource;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Columns\ToggleColumn;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Forms\Components\Grid;{$sectionImport}
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Components\Select;
use Happones\Kinetix\Forms\Components\Toggle;
use Happones\Kinetix\Forms\Components\Textarea;
use Happones\Kinetix\Forms\Components\DateTimePicker;
use Happones\Kinetix\Infolists\Infolist;
use Happones\Kinetix\Infolists\Components\TextEntry;
use Happones\Kinetix\Infolists\Components\IconEntry;{$infolistSectionImport}{$teamImports}

class {$resourceClass} extends Resource
{
    protected static ?string \$model = {$modelName}::class;

    public static function table(Table \$table): Table
    {
        return \$table
            ->columns([
{$tableColumnsStr}
            ]){$tableActions}
    }

    public static function form(Form \$form): Form
    {
        return \$form
            ->schema([
{$formSchemaStr}
            ]);
    }

    // Read-only detail shown in the table's View modal. Remove this method to
    // drop the View action.
    public static function infolist(Infolist \$infolist): Infolist
    {
        return \$infolist
            ->schema([
{$infolistSchemaStr}
            ]);
    }{$permissionHook}{$teamHooks}
}
PHP;

        $this->writeScaffoldFile($filePath, $template, "PHP Resource class: [app/Kinetix/Resources/{$resourceClass}.php]");
    }

    /**
     * Generate Kinetix Resource Controller.
     */
    protected function createController(
        string $modelName,
        string $resourceClass,
        string $pluralName,
        string $pluralSlug,
        bool $simple,
        bool $softDeletes,
        bool $teams = false
    ): void {
        $directory = app_path('Http/Controllers/Kinetix');
        $filePath  = "{$directory}/{$modelName}Controller.php";

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $softDeletesMethods = '';
        $routePrefix        = $pluralSlug;

        // Under a team-prefixed group every record route carries TWO required
        // parameters ({current_team}, then the record). Laravel injects route
        // params POSITIONALLY, so without a leading $current_team argument the
        // team segment lands in $record and findOrFail('{team}') 404s.
        $teamParam = $teams ? 'string $current_team, ' : '';

        // Policy-if-exists gate, matching every built-in Kinetix surface
        // (record modals, table writes, action serialization): with a policy
        // registered the ability is enforced; without one the check is skipped
        // — hence the loud "create the policy" next-step.
        $authorizeHelper = <<<PHP


    /**
     * Policy-if-exists, the same contract every built-in Kinetix surface uses:
     * when a policy is registered for {$modelName} the ability is enforced,
     * otherwise the check is skipped. Create one to lock this controller down:
     * `php artisan make:policy {$modelName}Policy --model={$modelName}`.
     */
    protected function authorizeAction(string \$ability, mixed \$target): void
    {
        if (Gate::getPolicyFor({$modelName}::class) !== null) {
            Gate::authorize(\$ability, \$target);
        }
    }
PHP;

        if ($softDeletes) {
            $softDeletesMethods = <<<PHP

    public function restore({$teamParam}string \$id)
    {
        // Through the resource's SCOPED query — an out-of-scope (e.g. another
        // team's) id must 404, exactly like show/edit/update/destroy.
        \$record = {$resourceClass}::getEloquentQuery()->onlyTrashed()->findOrFail(\$id);
        \$this->authorizeAction('restore', \$record);

        \$record->restore();

        // getUrl() keeps the {current_team} segment — a bare route() call
        // throws under a team-prefixed group (missing required parameter).
        return redirect({$resourceClass}::getUrl('index'))->with('kinetix_toast', __('kinetix.record_restored'));
    }

    public function forceDelete({$teamParam}string \$id)
    {
        \$record = {$resourceClass}::getEloquentQuery()->withTrashed()->findOrFail(\$id);
        \$this->authorizeAction('forceDelete', \$record);

        \$record->forceDelete();

        return redirect({$resourceClass}::getUrl('index'))->with('kinetix_toast', __('kinetix.record_force_deleted'));
    }
PHP;
        }

        if ($simple) {
            // Simple controller: a single index page. Columns, in-table modals
            // (create/edit/view/delete) and reorder are all declared on the
            // resource's table() — the controller just supplies the scoped
            // query. Modal writes are authorized by Kinetix's record endpoint
            // against the same policy.
            $template = <<<PHP
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kinetix;

use App\Http\Controllers\Controller;
use App\Kinetix\Resources\\{$resourceClass};
use App\Models\\{$modelName};
use Happones\Kinetix\Tables\Table;
use Illuminate\Support\Facades\Gate;

class {$modelName}Controller extends Controller
{
    public function index()
    {
        \$this->authorizeAction('viewAny', {$modelName}::class);

        // getEloquentQuery() scopes reads (and the modal endpoint's writes) —
        // e.g. to the current team. Edits fetch a FRESH copy from the server by
        // default; switch to the loaded row with
        // ->recordModals({$resourceClass}::class, 'row') on the resource, or the
        // `kinetix.tables.record_source` config.
        \$query = {$resourceClass}::getEloquentQuery();

        return inertia('Kinetix/{$pluralName}/Index', [
            'table' => {$resourceClass}::table(Table::make(\$query))->toArray(),
            'breadcrumbs' => {$resourceClass}::breadcrumbs('index'),
        ]);
    }
{$softDeletesMethods}{$authorizeHelper}
}
PHP;
        } else {
            // Full multi-page controller
            $template = <<<PHP
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kinetix;

use App\Http\Controllers\Controller;
use App\Kinetix\Resources\\{$resourceClass};
use App\Models\\{$modelName};
use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Actions\DeleteAction;
use Happones\Kinetix\Actions\EditAction;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Infolists\Infolist;
use Happones\Kinetix\Tables\Table;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class {$modelName}Controller extends Controller
{
    public function index()
    {
        \$this->authorizeAction('viewAny', {$modelName}::class);

        // The resource's query is the single scoping point (team isolation
        // lives there) — never rebuild the scope inline, or the listing
        // diverges from what show/edit/update/destroy resolve.
        \$query = {$resourceClass}::getEloquentQuery();

        // Columns + row/toolbar actions (View / Edit / Delete / Create) are
        // declared on the resource's table(); here we just render the query.
        return inertia('Kinetix/{$pluralName}/Index', [
            'table' => {$resourceClass}::table(Table::make(\$query))->toArray(),
            'breadcrumbs' => {$resourceClass}::breadcrumbs('index'),
        ]);
    }

    public function create()
    {
        \$this->authorizeAction('create', {$modelName}::class);

        \$form = {$resourceClass}::form(Form::make(new {$modelName}()))->fill();

        // URLs are resolved server-side (getUrl() fills the `{current_team}`
        // segment and route keys), so the page never rebuilds routes itself.
        return inertia('Kinetix/{$pluralName}/Create', [
            'form' => \$form->toArray(),
            'storeUrl' => {$resourceClass}::getUrl('store'),
            'cancelUrl' => {$resourceClass}::getUrl('index'),
            'breadcrumbs' => {$resourceClass}::breadcrumbs('create'),
        ]);
    }

    public function store(Request \$request)
    {
        \$this->authorizeAction('create', {$modelName}::class);

        \$form = {$resourceClass}::form(Form::make(new {$modelName}()));
        \$form->validate(\$request->all());

        // Through the resource's save hook — it stamps server-owned columns
        // (e.g. team_id on a team-aware resource), so customizing the hook
        // applies to these pages AND the in-table modal endpoint alike.
        \$record = {$modelName}::create({$resourceClass}::mutateFormDataBeforeSave(\$form->getState(\$request->all()), 'create'));

        // Destination configurable on the resource — getRedirectUrlAfterCreate()
        // (defaults to the index).
        // The toast message — customize freely; <KinetixToaster /> shows it.
        return redirect({$resourceClass}::getRedirectUrlAfterCreate(\$record))
            ->with('kinetix_toast', __('kinetix.record_created'));
    }

    public function show({$teamParam}string \$record)
    {
        // Resolve through the resource's SCOPED query — implicit route-model
        // binding would fetch by id alone, letting a team-prefixed URL render
        // another team's record. Out-of-scope ids 404 here instead.
        \$record = {$resourceClass}::getEloquentQuery()->findOrFail(\$record);
        \$this->authorizeAction('view', \$record);

        // Read-only detail: the resource's infolist() plus Edit/Delete actions
        // rendered in the page header (KinetixPageHeader) for quick redirects.
        return inertia('Kinetix/{$pluralName}/Show', [
            'infolist' => {$resourceClass}::infolist(Infolist::make(\$record))->toArray(),
            'actions' => Action::toArrayMany([
                // route() resolves per record and auto-hides if the route is absent.
                EditAction::make()->route('{$routePrefix}.edit'),
                DeleteAction::make()->route('{$routePrefix}.destroy', method: 'delete'),
            ], \$record),
            // Relation managers declared on the resource (auto-tabs when >1).
            'relations' => collect({$resourceClass}::relationManagersFor('view', \$record))
                ->map(fn (string \$manager) => \$manager::make(\$record)->toData())
                ->values(),
            'breadcrumbs' => {$resourceClass}::breadcrumbs('show', \$record),
        ]);
    }

    public function edit({$teamParam}string \$record)
    {
        \$record = {$resourceClass}::getEloquentQuery()->findOrFail(\$record);
        \$this->authorizeAction('update', \$record);

        \$form = {$resourceClass}::form(Form::make(\$record))->fill(\$record);

        return inertia('Kinetix/{$pluralName}/Edit', [
            'form' => \$form->toArray(),
            'updateUrl' => {$resourceClass}::getUrl('update', \$record),
            'cancelUrl' => {$resourceClass}::getUrl('index'),
            'relations' => collect({$resourceClass}::relationManagersFor('edit', \$record))
                ->map(fn (string \$manager) => \$manager::make(\$record)->toData())
                ->values(),
            'breadcrumbs' => {$resourceClass}::breadcrumbs('edit', \$record),
        ]);
    }

    public function update(Request \$request, {$teamParam}string \$record)
    {
        \$record = {$resourceClass}::getEloquentQuery()->findOrFail(\$record);
        \$this->authorizeAction('update', \$record);

        \$form = {$resourceClass}::form(Form::make(\$record));
        \$form->validate(\$request->all());

        // The save hook also strips server-owned columns on edit (a submitted
        // team_id can never move the record to another team).
        \$record->update({$resourceClass}::mutateFormDataBeforeSave(\$form->getState(\$request->all()), 'edit', \$record));

        // Destination configurable on the resource — getRedirectUrlAfterSave()
        // (defaults to staying on the edit page).
        return redirect({$resourceClass}::getRedirectUrlAfterSave(\$record))
            ->with('kinetix_toast', __('kinetix.record_updated'));
    }

    public function destroy({$teamParam}string \$record)
    {
        \$record = {$resourceClass}::getEloquentQuery()->findOrFail(\$record);
        \$this->authorizeAction('delete', \$record);

        \$record->delete();

        // getUrl() keeps the {current_team} segment — a bare route() call
        // throws under a team-prefixed group (missing required parameter).
        return redirect({$resourceClass}::getUrl('index'))->with('kinetix_toast', __('kinetix.record_deleted'));
    }
{$softDeletesMethods}{$authorizeHelper}
}
PHP;
        }

        $this->writeScaffoldFile($filePath, $template, "PHP Controller: [app/Http/Controllers/Kinetix/{$modelName}Controller.php]");
    }

    /**
     * Generate Vue page views.
     */
    protected function createVuePages(
        string $modelName,
        string $pluralName,
        string $pluralSlug,
        bool $simple,
        bool $softDeletes,
        array $formFields,
        array $tableColumns
    ): void {
        $directory = resource_path("js/pages/Kinetix/{$pluralName}");

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        if ($simple) {
            // Single-page resource: the table hosts every modal. The page is just
            // <KinetixTable :table> — create/edit/view/delete + reorder are driven
            // by the serialized table (Table::recordModals()), so there is no
            // modal markup or submit wiring to maintain here.
            $indexTemplate = <<<'VUE'
<script setup lang="ts">
import KinetixTable from '@/components/kinetix/KinetixTable.vue';
import type { KinetixBreadcrumb, KinetixTableData } from '@/types/kinetix';

// `breadcrumbs` is auto-derived from the resource; feed it to your app layout's
// <Breadcrumbs> (see https://happones.github.io/kinetix/breadcrumbs).
defineProps<{
  table: KinetixTableData;
  breadcrumbs?: KinetixBreadcrumb[];
}>();
</script>

<template>
  <!-- The table drives everything: Create (toolbar), View/Edit/Delete (per row)
       open modals hosted inside KinetixTable; drag handles reorder when enabled. -->
  <div class="flex h-full min-w-0 flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
    <KinetixTable :table="table" />
  </div>
</template>
VUE;

            $this->writeScaffoldFile("{$directory}/Index.vue", $indexTemplate, "Vue Page: [resources/js/pages/Kinetix/{$pluralName}/Index.vue] (Simple mode)");
        } else {
            // Create distinct multi-page views
            $indexTemplate = <<<'VUE'
<script setup lang="ts">
import KinetixTable from '@/components/kinetix/KinetixTable.vue';
import type { KinetixBreadcrumb, KinetixTableData } from '@/types/kinetix';

// `breadcrumbs` is auto-derived from the resource; feed it to your app layout's
// <Breadcrumbs> (see https://happones.github.io/kinetix/breadcrumbs).
defineProps<{
  table: KinetixTableData;
  breadcrumbs?: KinetixBreadcrumb[];
}>();
</script>

<template>
  <!-- The New button + row View/Edit/Delete come from the resource table()
       actions (self-hiding when a route isn't registered). -->
  <div class="flex h-full min-w-0 flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
    <KinetixTable :table="table" />
  </div>
</template>
VUE;

            $createTemplate = <<<VUE
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import KinetixButton from '@/components/kinetix/KinetixButton.vue';
import KinetixForm from '@/components/kinetix/KinetixForm.vue';
import KinetixPageHeader from '@/components/kinetix/KinetixPageHeader.vue';
import type { KinetixBreadcrumb } from '@/types/kinetix';

// `storeUrl` / `cancelUrl` are resolved server-side (Resource::getUrl()), so
// team-scoped routes ({current_team}) work with no client-side team handling.
const props = defineProps<{
  form: any;
  storeUrl: string;
  cancelUrl: string;
  breadcrumbs?: KinetixBreadcrumb[];
}>();

const saving = ref(false);

const handleSubmit = (values: Record<string, any>) => {
  router.post(props.storeUrl, values, {
    preserveScroll: true,
    onStart: () => (saving.value = true),
    onFinish: () => (saving.value = false),
  });
};

const handleCancel = () => {
  router.get(props.cancelUrl);
};
</script>

<template>
  <!-- Same width and padding as the Index page, so the resource's pages
       share one visual frame. -->
  <div class="flex h-full min-w-0 flex-1 flex-col gap-6 rounded-xl p-4">
    <KinetixPageHeader
      heading="Create {$modelName}"
      description="Add a new {$modelName} record."
    />

    <!-- The form's own Section provides the card surface — no extra wrapper,
         so schemas never render card-inside-card. -->
    <KinetixForm :form="form" @submit="handleSubmit">
      <template #default>
        <!-- Full-width stacked buttons on mobile (primary on top),
             right-aligned row on desktop. -->
        <div class="flex w-full flex-col-reverse gap-3 sm:flex-row sm:justify-end">
          <!-- KinetixButton owns the pending behaviour: `loading` disables the
               button and shows a spinner, same as every Kinetix action button. -->
          <KinetixButton
            variant="outline"
            class="w-full sm:w-auto"
            :disabled="saving"
            @click="handleCancel"
          >
            Cancel
          </KinetixButton>

          <KinetixButton
            type="submit"
            class="w-full sm:w-auto"
            :loading="saving"
          >
            {{ saving ? 'Creating…' : 'Create {$modelName}' }}
          </KinetixButton>
        </div>
      </template>
    </KinetixForm>
  </div>
</template>
VUE;

            $editTemplate = <<<VUE
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import KinetixButton from '@/components/kinetix/KinetixButton.vue';
import KinetixForm from '@/components/kinetix/KinetixForm.vue';
import KinetixPageHeader from '@/components/kinetix/KinetixPageHeader.vue';
import KinetixRelationManagers from '@/components/kinetix/KinetixRelationManagers.vue';
import type {
  KinetixBreadcrumb,
  KinetixRelationManagerData,
} from '@/types/kinetix';

// `updateUrl` / `cancelUrl` are resolved server-side (Resource::getUrl()), so
// team-scoped routes ({current_team}) work with no client-side team handling.
// `relations` are the resource's relation managers (auto-tabs when more than one).
const props = defineProps<{
  form: any;
  updateUrl: string;
  cancelUrl: string;
  relations?: KinetixRelationManagerData[];
  breadcrumbs?: KinetixBreadcrumb[];
}>();

const saving = ref(false);

const handleSubmit = (values: Record<string, any>) => {
  router.put(props.updateUrl, values, {
    preserveScroll: true,
    onStart: () => (saving.value = true),
    onFinish: () => (saving.value = false),
  });
};

const handleCancel = () => {
  router.get(props.cancelUrl);
};
</script>

<template>
  <!-- Same width and padding as the Index page, so the resource's pages
       share one visual frame. -->
  <div class="flex h-full min-w-0 flex-1 flex-col gap-6 rounded-xl p-4">
    <KinetixPageHeader
      heading="Edit {$modelName}"
      description="Update this {$modelName}'s details."
    />

    <!-- The form's own Section provides the card surface — no extra wrapper,
         so schemas never render card-inside-card. -->
    <KinetixForm :form="form" @submit="handleSubmit">
      <template #default>
        <!-- Full-width stacked buttons on mobile (primary on top),
             right-aligned row on desktop. -->
        <div class="flex w-full flex-col-reverse gap-3 sm:flex-row sm:justify-end">
          <!-- KinetixButton owns the pending behaviour: `loading` disables the
               button and shows a spinner, same as every Kinetix action button. -->
          <KinetixButton
            variant="outline"
            class="w-full sm:w-auto"
            :disabled="saving"
            @click="handleCancel"
          >
            Cancel
          </KinetixButton>

          <KinetixButton
            type="submit"
            class="w-full sm:w-auto"
            :loading="saving"
          >
            {{ saving ? 'Saving…' : 'Save changes' }}
          </KinetixButton>
        </div>
      </template>
    </KinetixForm>

    <!-- Relation managers declared on the resource (auto-tabs when >1). -->
    <KinetixRelationManagers v-if="relations?.length" :managers="relations" />
  </div>
</template>
VUE;

            $showTemplate = <<<VUE
<script setup lang="ts">
import KinetixInfolist from '@/components/kinetix/KinetixInfolist.vue';
import KinetixPageHeader from '@/components/kinetix/KinetixPageHeader.vue';
import KinetixRelationManagers from '@/components/kinetix/KinetixRelationManagers.vue';
import type {
  KinetixAction,
  KinetixBreadcrumb,
  KinetixInfolistData,
  KinetixRelationManagerData,
} from '@/types/kinetix';

// `actions` (Edit / Delete) render top-right in the header for quick redirects;
// `infolist` is the resource's read-only detail schema; `relations` are the
// resource's relation managers (auto-tabs when more than one).
defineProps<{
  infolist: KinetixInfolistData;
  actions: KinetixAction[];
  relations?: KinetixRelationManagerData[];
  breadcrumbs?: KinetixBreadcrumb[];
}>();
</script>

<template>
  <!-- Same width and padding as the Index page, so the resource's pages
       share one visual frame. -->
  <div class="flex h-full min-w-0 flex-1 flex-col gap-6 rounded-xl p-4">
    <KinetixPageHeader heading="{$modelName} details" :actions="actions" />
    <KinetixInfolist :infolist="infolist" />
    <KinetixRelationManagers v-if="relations?.length" :managers="relations" />
  </div>
</template>
VUE;

            $this->writeScaffoldFile("{$directory}/Index.vue", $indexTemplate, "Vue Page: [resources/js/pages/Kinetix/{$pluralName}/Index.vue]");
            $this->writeScaffoldFile("{$directory}/Create.vue", $createTemplate, "Vue Page: [resources/js/pages/Kinetix/{$pluralName}/Create.vue]");
            $this->writeScaffoldFile("{$directory}/Edit.vue", $editTemplate, "Vue Page: [resources/js/pages/Kinetix/{$pluralName}/Edit.vue]");
            $this->writeScaffoldFile("{$directory}/Show.vue", $showTemplate, "Vue Page: [resources/js/pages/Kinetix/{$pluralName}/Show.vue]");
        }
    }
}
