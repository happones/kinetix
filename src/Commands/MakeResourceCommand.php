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
                            {--generate : Read database table columns to automatically populate form and table schemas}';

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
        $this->createResourceClass($modelName, $resourceClass, $formFields, $tableColumns, $infolistEntries, $teams, $simple, $reorderable, $pluralSlug);

        // 2. Create Resource Controller
        $this->createController($modelName, $resourceClass, $pluralName, $pluralSlug, $simple, $softDeletes, $teams);

        // 3. Create Vue frontend pages
        $this->createVuePages($modelName, $pluralName, $pluralSlug, $simple, $softDeletes, $formFields, $tableColumns);

        $this->info("\nKinetix Resource [{$modelName}] scaffolded successfully!");
        $this->comment('Next steps:');
        $this->line('1. Add the resource route to your routes/web.php file:');

        $controllerRef = "\\App\\Http\\Controllers\\Kinetix\\{$modelName}Controller::class";

        // Simple resources are single-page: create/edit/view/delete run through
        // Kinetix's own signed record endpoint (Table::recordModals()), so only
        // the index route is needed.
        $indent = $teams ? '       ' : '   ';
        $emit   = function (string $line) use ($indent): void {
            $this->line($indent.$line);
        };

        if ($teams) {
            $this->line('   // Team-aware: nest under the {current_team} segment Kinetix uses.');
            $this->line("   Route::prefix('{current_team}')->group(function () {");
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
            $this->line('   });');
        }

        return self::SUCCESS;
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

            foreach ($columns as $column) {
                $colName    = $column['name'];
                $colType    = strtolower($column['type_name'] ?? 'string');
                $isNullable = $column['nullable'] ?? true;

                // Skip IDs and timestamps
                if (in_array($colName, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
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
        string $pluralSlug = ''
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

        // Actions live on the table config (single source of truth). Simple mode
        // opens in-table modals; full mode navigates to the scaffolded pages via
        // Action::route() — which auto-hides a button when its route isn't
        // registered, so nothing renders as a dead link.
        $reorderableChain = $reorderable ? "\n            ->reorderable()" : '';

        // Row actions are grouped into a shadcn-style "⋯" dropdown by default;
        // the empty group auto-hides when every child is unauthorized/unrouted.
        if ($simple) {
            $tableActions = <<<PHP

            ->recordModals(static::class){$reorderableChain}
            ->toolbarActions([
                CreateAction::make()->modal('create'),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()->modal('view'),
                    EditAction::make()->modal('edit'),
                    DeleteAction::make()->modal('delete'),
                ]),
            ]);
PHP;
        } else {
            $tableActions = <<<PHP
{$reorderableChain}
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()->route('{$pluralSlug}.show'),
                    EditAction::make()->route('{$pluralSlug}.edit'),
                    DeleteAction::make()->route('{$pluralSlug}.destroy', method: 'delete'),
                ]),
            ])
            ->toolbarActions([
                CreateAction::make()->route('{$pluralSlug}.create'),
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
     * Stamps the owning team on create. `team_id` is server-owned: it is never
     * part of the form schema, so a submitted value can't reassign the record.
     */
    public static function mutateFormDataBeforeSave(array \$data, string \$operation, ?\Illuminate\Database\Eloquent\Model \$record = null): array
    {
        if (\$operation === 'create') {
            \$data['team_id'] = KinetixTeams::currentTeamKey();
        }

        return \$data;
    }
PHP;
        }

        $template = <<<PHP
<?php

declare(strict_types=1);

namespace App\Kinetix\Resources;

use App\Models\\{$modelName};
use Happones\Kinetix\Actions\ActionGroup;
use Happones\Kinetix\Actions\CreateAction;
use Happones\Kinetix\Actions\DeleteAction;
use Happones\Kinetix\Actions\EditAction;
use Happones\Kinetix\Actions\ViewAction;
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
use Happones\Kinetix\Infolists\Components\IconEntry;{$teamImports}

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
{$infolistEntriesStr}
            ]);
    }{$teamHooks}
}
PHP;

        File::put($filePath, $template);
        $this->line("Created PHP Resource class: [app/Kinetix/Resources/{$resourceClass}.php]");
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

        // Team-aware index signature, base query, and create expression. The
        // tenant comes from KinetixTeams (the `{current_team}` segment, with a
        // membership check) — not `$user->currentTeam`, which ignores the URL.
        // Adjust the `team_id` column to match your application's team schema.
        $indexParams = $teams ? 'Request $request' : '';
        $indexQuery  = $teams
            ? "{$modelName}::where('team_id', KinetixTeams::currentTeamKey())"
            : "{$modelName}::query()";
        $createExpr = $teams
            ? "{$modelName}::create(array_merge(\$form->getState(\$request->all()), ['team_id' => KinetixTeams::currentTeamKey()]))"
            : "{$modelName}::create(\$form->getState(\$request->all()))";

        $softDeletesTraits  = '';
        $softDeletesMethods = '';
        $routePrefix        = $pluralSlug;

        if ($softDeletes) {
            $softDeletesMethods = <<<PHP

    public function restore(Request \$request, \$id)
    {
        \$record = {$modelName}::onlyTrashed()->findOrFail(\$id);
        \$record->restore();

        return redirect()->route('{$routePrefix}.index')->with('message', 'Record restored successfully.');
    }

    public function forceDelete(Request \$request, \$id)
    {
        \$record = {$modelName}::withTrashed()->findOrFail(\$id);
        \$record->forceDelete();

        return redirect()->route('{$routePrefix}.index')->with('message', 'Record permanently deleted.');
    }
PHP;
        }

        // Only the team-aware base query references the tenant resolver.
        $teamsImport = $teams ? "\nuse Happones\Kinetix\Support\KinetixTeams;" : '';

        if ($simple) {
            // Simple controller: a single index page. Columns, in-table modals
            // (create/edit/view/delete) and reorder are all declared on the
            // resource's table() — the controller just supplies the scoped query.
            // The model + Request are only referenced by the soft-delete
            // restore/forceDelete methods; omit the imports otherwise.
            $modelImport   = $softDeletes ? "\nuse App\Models\\{$modelName};" : '';
            $requestImport = $softDeletes ? "\nuse Illuminate\Http\Request;" : '';
            $withTrashed   = $softDeletes ? "\n        \$query = \$query->withTrashed();" : '';

            $template = <<<PHP
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kinetix;

use App\Http\Controllers\Controller;
use App\Kinetix\Resources\\{$resourceClass};{$modelImport}
use Happones\Kinetix\Tables\Table;{$teamsImport}{$requestImport}

class {$modelName}Controller extends Controller
{
    public function index()
    {
        // getEloquentQuery() scopes reads (and the modal endpoint's writes) —
        // e.g. to the current team. Edits fetch a FRESH copy from the server by
        // default; switch to the loaded row with
        // ->recordModals({$resourceClass}::class, 'row') on the resource, or the
        // `kinetix.tables.record_source` config.
        \$query = {$resourceClass}::getEloquentQuery();{$withTrashed}

        return inertia('Kinetix/{$pluralName}/Index', [
            'table' => {$resourceClass}::table(Table::make(\$query))->toArray(),
            'breadcrumbs' => {$resourceClass}::breadcrumbs('index'),
        ]);
    }
{$softDeletesMethods}
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
use Happones\Kinetix\Tables\Table;{$teamsImport}
use Illuminate\Http\Request;

class {$modelName}Controller extends Controller
{
    public function index({$indexParams})
    {
        \$query = {$indexQuery};
PHP;
            if ($softDeletes) {
                $template .= "\n        \$query = \$query->withTrashed();";
            }
            $template .= <<<PHP

        // Columns + row/toolbar actions (View / Edit / Delete / Create) are
        // declared on the resource's table(); here we just render the query.
        return inertia('Kinetix/{$pluralName}/Index', [
            'table' => {$resourceClass}::table(Table::make(\$query))->toArray(),
            'breadcrumbs' => {$resourceClass}::breadcrumbs('index'),
        ]);
    }

    public function create()
    {
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
        \$form = {$resourceClass}::form(Form::make(new {$modelName}()));
        \$form->validate(\$request->all());

        \$record = {$createExpr};

        // Destination configurable on the resource — getRedirectUrlAfterCreate()
        // (defaults to the index).
        return redirect({$resourceClass}::getRedirectUrlAfterCreate(\$record))
            ->with('message', 'Record created successfully.');
    }

    public function show({$modelName} \$record)
    {
        // Read-only detail: the resource's infolist() plus Edit/Delete actions
        // rendered in the page header (KinetixPageHeader) for quick redirects.
        return inertia('Kinetix/{$pluralName}/Show', [
            'infolist' => {$resourceClass}::infolist(Infolist::make(\$record))->toArray(),
            'actions' => Action::toArrayMany([
                // route() resolves per record and auto-hides if the route is absent.
                EditAction::make()->route('{$routePrefix}.edit'),
                DeleteAction::make()->route('{$routePrefix}.destroy', method: 'delete'),
            ], \$record),
            'breadcrumbs' => {$resourceClass}::breadcrumbs('show', \$record),
        ]);
    }

    public function edit({$modelName} \$record)
    {
        \$form = {$resourceClass}::form(Form::make(\$record))->fill(\$record);

        return inertia('Kinetix/{$pluralName}/Edit', [
            'form' => \$form->toArray(),
            'updateUrl' => {$resourceClass}::getUrl('update', \$record),
            'cancelUrl' => {$resourceClass}::getUrl('index'),
            'breadcrumbs' => {$resourceClass}::breadcrumbs('edit', \$record),
        ]);
    }

    public function update(Request \$request, {$modelName} \$record)
    {
        \$form = {$resourceClass}::form(Form::make(\$record));
        \$form->validate(\$request->all());

        \$record->update(\$form->getState(\$request->all()));

        // Destination configurable on the resource — getRedirectUrlAfterSave()
        // (defaults to staying on the edit page).
        return redirect({$resourceClass}::getRedirectUrlAfterSave(\$record))
            ->with('message', 'Record updated successfully.');
    }

    public function destroy({$modelName} \$record)
    {
        \$record->delete();

        return redirect()->route('{$routePrefix}.index')->with('message', 'Record deleted successfully.');
    }
{$softDeletesMethods}
}
PHP;
        }

        File::put($filePath, $template);
        $this->line("Created PHP Controller: [app/Http/Controllers/Kinetix/{$modelName}Controller.php]");
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

            File::put("{$directory}/Index.vue", $indexTemplate);
            $this->line("Created Vue Page: [resources/js/pages/Kinetix/{$pluralName}/Index.vue] (Simple mode)");
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
  <!-- Forms read best at a constrained measure; padding scales with viewport. -->
  <div class="mx-auto w-full max-w-3xl space-y-6 p-4 sm:p-6 lg:p-8">
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
import type { KinetixBreadcrumb } from '@/types/kinetix';

// `updateUrl` / `cancelUrl` are resolved server-side (Resource::getUrl()), so
// team-scoped routes ({current_team}) work with no client-side team handling.
const props = defineProps<{
  form: any;
  updateUrl: string;
  cancelUrl: string;
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
  <!-- Forms read best at a constrained measure; padding scales with viewport. -->
  <div class="mx-auto w-full max-w-3xl space-y-6 p-4 sm:p-6 lg:p-8">
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
  </div>
</template>
VUE;

            $showTemplate = <<<VUE
<script setup lang="ts">
import KinetixInfolist from '@/components/kinetix/KinetixInfolist.vue';
import KinetixPageHeader from '@/components/kinetix/KinetixPageHeader.vue';
import type {
  KinetixAction,
  KinetixBreadcrumb,
  KinetixInfolistData,
} from '@/types/kinetix';

// `actions` (Edit / Delete) render top-right in the header for quick redirects;
// `infolist` is the resource's read-only detail schema.
defineProps<{
  infolist: KinetixInfolistData;
  actions: KinetixAction[];
  breadcrumbs?: KinetixBreadcrumb[];
}>();
</script>

<template>
  <div class="mx-auto w-full max-w-5xl space-y-6 p-4 sm:p-6 lg:p-8">
    <KinetixPageHeader heading="{$modelName} details" :actions="actions" />
    <KinetixInfolist :infolist="infolist" />
  </div>
</template>
VUE;

            File::put("{$directory}/Index.vue", $indexTemplate);
            File::put("{$directory}/Create.vue", $createTemplate);
            File::put("{$directory}/Edit.vue", $editTemplate);
            File::put("{$directory}/Show.vue", $showTemplate);
            $this->line("Created Vue Pages: Index, Create, Edit, Show in [resources/js/pages/Kinetix/{$pluralName}/]");
        }
    }
}
