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
        $modelName = ucfirst(Str::camel(Str::singular($name)));
        $resourceClass = "{$modelName}Resource";
        $pluralName = Str::plural($modelName);
        $pluralSlug = Str::kebab($pluralName);
        
        $simple = $this->option('simple');
        $softDeletes = $this->option('soft-deletes');
        $generate = $this->option('generate');

        // Check if model exists
        $modelClass = $this->resolveModelClass($modelName);
        if (!$modelClass) {
            $this->error("Eloquent Model App\\Models\\{$modelName} does not exist. Please create the model first.");
            return self::FAILURE;
        }

        // Generate schema lists
        [$formFields, $tableColumns] = $this->getSchemaDefinitions($modelClass, $generate, $softDeletes);

        // 1. Create PHP Resource Class
        $this->createResourceClass($modelName, $resourceClass, $formFields, $tableColumns);

        // 2. Create Resource Controller
        $this->createController($modelName, $resourceClass, $pluralName, $pluralSlug, $simple, $softDeletes);

        // 3. Create Vue frontend pages
        $this->createVuePages($modelName, $pluralName, $pluralSlug, $simple, $softDeletes, $formFields, $tableColumns);

        $this->info("\nKinetix Resource [{$modelName}] scaffolded successfully!");
        $this->comment("Next steps:");
        $this->line("1. Add the resource route to your routes/web.php file:");
        $this->line("   Route::resource('{$pluralSlug}', \\App\\Http\\Controllers\\Kinetix\\{$modelName}Controller::class);");
        if ($softDeletes) {
            $this->line("   Route::post('{$pluralSlug}/{id}/restore', [\\App\\Http\\Controllers\\Kinetix\\{$modelName}Controller::class, 'restore'])->name('{$pluralSlug}.restore');");
            $this->line("   Route::delete('{$pluralSlug}/{id}/force-delete', [\\App\\Http\\Controllers\\Kinetix\\{$modelName}Controller::class, 'forceDelete'])->name('{$pluralSlug}.force-delete');");
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
        $formFields = [];
        $tableColumns = [];

        if (!$generate) {
            $formFields[] = "                TextInput::make('title')->required(),";
            $tableColumns[] = "                TextColumn::make('title')->searchable()->sortable(),";
            return [$formFields, $tableColumns];
        }

        try {
            $model = new $modelClass();
            $tableName = $model->getTable();

            if (method_exists(Schema::class, 'getColumns')) {
                $columns = Schema::getColumns($tableName);
            } else {
                $columnNames = Schema::getColumnListing($tableName);
                $columns = [];
                foreach ($columnNames as $colName) {
                    $columns[] = [
                        'name' => $colName,
                        'type_name' => 'string',
                        'nullable' => true,
                    ];
                }
            }

            foreach ($columns as $column) {
                $colName = $column['name'];
                $colType = strtolower($column['type_name'] ?? 'string');
                $isNullable = $column['nullable'] ?? true;

                // Skip IDs and timestamps
                if (in_array($colName, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                    continue;
                }

                $formRule = "";
                $tableRule = "";

                if ($colType === 'boolean' || $colType === 'tinyint' || Str::startsWith($colName, 'is_')) {
                    $formRule = "                Toggle::make('{$colName}')";
                    $tableRule = "                ToggleColumn::make('{$colName}')";
                } elseif (Str::contains($colName, 'email')) {
                    $formRule = "                TextInput::make('{$colName}')->email()";
                    $tableRule = "                TextColumn::make('{$colName}')";
                } elseif ($colType === 'text') {
                    $formRule = "                Textarea::make('{$colName}')";
                    $tableRule = "                TextColumn::make('{$colName}')->limit(50)";
                } elseif (in_array($colType, ['datetime', 'timestamp', 'date'], true)) {
                    $formRule = "                DateTimePicker::make('{$colName}')";
                    $tableRule = "                TextColumn::make('{$colName}')->dateTime()";
                } elseif (Str::contains($colType, 'int') || in_array($colType, ['decimal', 'float', 'double'], true)) {
                    $formRule = "                TextInput::make('{$colName}')->numeric()";
                    $tableRule = "                TextColumn::make('{$colName}')->sortable()";
                } else {
                    $formRule = "                TextInput::make('{$colName}')";
                    $tableRule = "                TextColumn::make('{$colName}')->searchable()->sortable()";
                }

                if (!$isNullable) {
                    $formRule .= "->required()";
                }

                $formFields[] = $formRule . ",";
                $tableColumns[] = $tableRule . ",";
            }

            if (empty($formFields)) {
                $formFields[] = "                TextInput::make('title')->required(),";
                $tableColumns[] = "                TextColumn::make('title')->searchable()->sortable(),";
            }
        } catch (\Exception $e) {
            $formFields[] = "                TextInput::make('title')->required(),";
            $tableColumns[] = "                TextColumn::make('title')->searchable()->sortable(),";
        }

        return [$formFields, $tableColumns];
    }

    /**
     * Generate Kinetix Resource PHP config class.
     */
    protected function createResourceClass(string $modelName, string $resourceClass, array $formFields, array $tableColumns): void
    {
        $directory = app_path('Kinetix/Resources');
        $filePath = "{$directory}/{$resourceClass}.php";

        if (!File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $formFieldsStr = implode("\n", $formFields);
        $tableColumnsStr = implode("\n", $tableColumns);

        $template = <<<PHP
<?php

declare(strict_types=1);

namespace App\Kinetix\Resources;

use App\Models\\{$modelName};
use Happones\Kinetix\Resources\Resource;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Columns\ToggleColumn;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Forms\Components\Grid;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Components\Select;
use Happones\Kinetix\Forms\Components\Toggle;
use Happones\Kinetix\Forms\Components\Textarea;
use Happones\Kinetix\Forms\Components\DateTimePicker;

class {$resourceClass} extends Resource
{
    protected static ?string \$model = {$modelName}::class;

    public static function table(Table \$table): Table
    {
        return \$table
            ->columns([
{$tableColumnsStr}
            ]);
    }

    public static function form(Form \$form): Form
    {
        return \$form
            ->schema([
{$formFieldsStr}
            ]);
    }
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
        bool $softDeletes
    ): void {
        $directory = app_path('Http/Controllers/Kinetix');
        $filePath = "{$directory}/{$modelName}Controller.php";

        if (!File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $softDeletesTraits = "";
        $softDeletesMethods = "";
        $routePrefix = $pluralSlug;

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

        if ($simple) {
            // Simple controller: single index and crud updates
            $template = <<<PHP
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kinetix;

use App\Http\Controllers\Controller;
use App\Kinetix\Resources\\{$resourceClass};
use App\Models\\{$modelName};
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Tables\Table;
use Illuminate\Http\Request;

class {$modelName}Controller extends Controller
{
    public function index()
    {
        \$query = {$modelName}::query();
PHP;
            if ($softDeletes) {
                $template .= "\n        \$query = \$query->withTrashed();";
            }
            $template .= <<<PHP

        \$table = {$resourceClass}::table(Table::make(\$query));
        \$form = {$resourceClass}::form(Form::make(new {$modelName}()));

        return inertia('Kinetix/{$pluralName}/Index', [
            'table' => \$table->toArray(),
            'formBlueprint' => \$form->toArray(),
        ]);
    }

    public function store(Request \$request)
    {
        \$form = {$resourceClass}::form(Form::make(new {$modelName}()));
        \$form->validate(\$request->all());

        {$modelName}::create(\$form->getState(\$request->all()));

        return redirect()->route('{$routePrefix}.index')->with('message', 'Record created successfully.');
    }

    public function update(Request \$request, {$modelName} \$record)
    {
        \$form = {$resourceClass}::form(Form::make(\$record));
        \$form->validate(\$request->all());

        \$record->update(\$form->getState(\$request->all()));

        return redirect()->route('{$routePrefix}.index')->with('message', 'Record updated successfully.');
    }

    public function destroy({$modelName} \$record)
    {
        \$record->delete();

        return redirect()->route('{$routePrefix}.index')->with('message', 'Record deleted successfully.');
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
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Tables\Table;
use Illuminate\Http\Request;

class {$modelName}Controller extends Controller
{
    public function index()
    {
        \$query = {$modelName}::query();
PHP;
            if ($softDeletes) {
                $template .= "\n        \$query = \$query->withTrashed();";
            }
            $template .= <<<PHP

        \$table = {$resourceClass}::table(Table::make(\$query));

        return inertia('Kinetix/{$pluralName}/Index', [
            'table' => \$table->toArray(),
        ]);
    }

    public function create()
    {
        \$form = {$resourceClass}::form(Form::make(new {$modelName}()))->fill();

        return inertia('Kinetix/{$pluralName}/Create', [
            'form' => \$form->toArray(),
        ]);
    }

    public function store(Request \$request)
    {
        \$form = {$resourceClass}::form(Form::make(new {$modelName}()));
        \$form->validate(\$request->all());

        {$modelName}::create(\$form->getState(\$request->all()));

        return redirect()->route('{$routePrefix}.index')->with('message', 'Record created successfully.');
    }

    public function edit({$modelName} \$record)
    {
        \$form = {$resourceClass}::form(Form::make(\$record))->fill(\$record);

        return inertia('Kinetix/{$pluralName}/Edit', [
            'form' => \$form->toArray(),
            'recordId' => \$record->getKey(),
        ]);
    }

    public function update(Request \$request, {$modelName} \$record)
    {
        \$form = {$resourceClass}::form(Form::make(\$record));
        \$form->validate(\$request->all());

        \$record->update(\$form->getState(\$request->all()));

        return redirect()->route('{$routePrefix}.index')->with('message', 'Record updated successfully.');
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

        if (!File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        if ($simple) {
            // Create single Index view with modals
            $indexTemplate = <<<VUE
<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import KinetixTable from '@/components/kinetix/KinetixTable.vue';
import KinetixForm from '@/components/kinetix/KinetixForm.vue';
import type { KinetixTableData } from '@/types';

const props = defineProps<{
  table: KinetixTableData;
  formBlueprint: any;
}>();

const isModalOpen = ref(false);
const isEditing = ref(false);
const activeRecordId = ref<number | null>(null);
const activeForm = ref<any>({ ...props.formBlueprint });

const openCreateModal = () => {
  isEditing.value = false;
  activeRecordId.value = null;
  activeForm.value = { ...props.formBlueprint, data: {} };
  isModalOpen.value = true;
};

const openEditModal = (record: any) => {
  isEditing.value = true;
  activeRecordId.value = record.id;
  activeForm.value = { ...props.formBlueprint, data: { ...record } };
  isModalOpen.value = true;
};

const handleFormSubmit = (values: Record<string, any>) => {
  if (isEditing.value && activeRecordId.value) {
    router.put(`/{$pluralSlug}/\${activeRecordId.value}`, values, {
      preserveScroll: true,
      onSuccess: () => {
        isModalOpen.value = false;
      },
    });
  } else {
    router.post('/{$pluralSlug}', values, {
      preserveScroll: true,
      onSuccess: () => {
        isModalOpen.value = false;
      },
    });
  }
};
</script>

<template>
  <div class="p-8 max-w-7xl mx-auto space-y-6">
    <div class="flex justify-between items-center">
      <div>
        <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">{$pluralName} Directory</h1>
        <p class="text-sm text-neutral-500">Manage database entries inline or via modals.</p>
      </div>

      <button
        @click="openCreateModal"
        class="inline-flex items-center justify-center rounded-lg text-sm font-semibold h-9 px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white shadow transition-colors"
      >
        New Entry
      </button>
    </div>

    <!-- Render Kinetix Listing Table -->
    <KinetixTable :table="table" />

    <!-- CRUD Form Dialog Modal -->
    <div
      v-if="isModalOpen"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 transition-opacity"
      @click.self="isModalOpen = false"
    >
      <div class="w-full max-w-2xl bg-white dark:bg-neutral-900 border dark:border-neutral-800 rounded-xl shadow-xl overflow-hidden flex flex-col max-h-[90vh]">
        <div class="p-6 border-b dark:border-neutral-800 flex justify-between items-center">
          <h3 class="font-semibold text-lg text-neutral-900 dark:text-white">
            {{ isEditing ? 'Edit Entry Details' : 'Create New Entry' }}
          </h3>
          <button 
            @click="isModalOpen = false" 
            class="text-neutral-400 hover:text-neutral-500"
          >
            &times;
          </button>
        </div>

        <div class="p-6 overflow-y-auto">
          <KinetixForm :form="activeForm" @submit="handleFormSubmit">
            <template #default>
              <div class="flex justify-end gap-3 mt-6">
                <button
                  type="button"
                  @click="isModalOpen = false"
                  class="px-4 py-2 text-sm font-semibold rounded-lg border border-neutral-300 dark:border-neutral-700 hover:bg-neutral-100 dark:hover:bg-neutral-800 transition-colors"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  class="px-4 py-2 text-sm font-semibold rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white transition-colors"
                >
                  {{ isEditing ? 'Save Changes' : 'Create Entry' }}
                </button>
              </div>
            </template>
          </KinetixForm>
        </div>
      </div>
    </div>
  </div>
</template>
VUE;

            File::put("{$directory}/Index.vue", $indexTemplate);
            $this->line("Created Vue Page: [resources/js/pages/Kinetix/{$pluralName}/Index.vue] (Simple mode)");
        } else {
            // Create distinct multi-page views
            $indexTemplate = <<<VUE
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import KinetixTable from '@/components/kinetix/KinetixTable.vue';
import type { KinetixTableData } from '@/types';

defineProps<{
  table: KinetixTableData;
}>();
</script>

<template>
  <div class="p-8 max-w-7xl mx-auto space-y-6">
    <div class="flex justify-between items-center">
      <div>
        <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">{$pluralName} Directory</h1>
        <p class="text-sm text-neutral-500">Manage database list records.</p>
      </div>

      <button
        @click="router.get('/{$pluralSlug}/create')"
        class="inline-flex items-center justify-center rounded-lg text-sm font-semibold h-9 px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white shadow transition-colors"
      >
        New Entry
      </button>
    </div>

    <!-- Render Kinetix Table -->
    <KinetixTable :table="table" />
  </div>
</template>
VUE;

            $createTemplate = <<<VUE
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import KinetixForm from '@/components/kinetix/KinetixForm.vue';

defineProps<{
  form: any;
}>();

const handleSubmit = (values: Record<string, any>) => {
  router.post('/{$pluralSlug}', values, {
    preserveScroll: true,
  });
};
</script>

<template>
  <div class="p-8 max-w-3xl mx-auto space-y-6">
    <div>
      <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">Create {$modelName}</h1>
      <p class="text-sm text-neutral-500">Add a new record to the database.</p>
    </div>

    <div class="bg-white dark:bg-neutral-950 border dark:border-neutral-800 rounded-xl shadow-sm p-6">
      <KinetixForm :form="form" @submit="handleSubmit">
        <template #default>
          <div class="flex justify-end gap-3 mt-6">
            <button
              type="button"
              @click="router.get('/{$pluralSlug}')"
              class="px-4 py-2 text-sm font-semibold rounded-lg border border-neutral-300 dark:border-neutral-700 hover:bg-neutral-100 dark:hover:bg-neutral-800 transition-colors"
            >
              Cancel
            </button>
            
            <button
              type="submit"
              class="px-4 py-2 text-sm font-semibold rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white transition-colors"
            >
              Create Record
            </button>
          </div>
        </template>
      </KinetixForm>
    </div>
  </div>
</template>
VUE;

            $editTemplate = <<<VUE
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import KinetixForm from '@/components/kinetix/KinetixForm.vue';

const props = defineProps<{
  form: any;
  recordId: number;
}>();

const handleSubmit = (values: Record<string, any>) => {
  router.put(`/{$pluralSlug}/\${props.recordId}`, values, {
    preserveScroll: true,
  });
};
</script>

<template>
  <div class="p-8 max-w-3xl mx-auto space-y-6">
    <div>
      <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">Edit {$modelName}</h1>
      <p class="text-sm text-neutral-500">Modify the active record details.</p>
    </div>

    <div class="bg-white dark:bg-neutral-950 border dark:border-neutral-800 rounded-xl shadow-sm p-6">
      <KinetixForm :form="form" @submit="handleSubmit">
        <template #default>
          <div class="flex justify-end gap-3 mt-6">
            <button
              type="button"
              @click="router.get('/{$pluralSlug}')"
              class="px-4 py-2 text-sm font-semibold rounded-lg border border-neutral-300 dark:border-neutral-700 hover:bg-neutral-100 dark:hover:bg-neutral-800 transition-colors"
            >
              Cancel
            </button>
            
            <button
              type="submit"
              class="px-4 py-2 text-sm font-semibold rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white transition-colors"
            >
              Save Changes
            </button>
          </div>
        </template>
      </KinetixForm>
    </div>
  </div>
</template>
VUE;

            File::put("{$directory}/Index.vue", $indexTemplate);
            File::put("{$directory}/Create.vue", $createTemplate);
            File::put("{$directory}/Edit.vue", $editTemplate);
            $this->line("Created Vue Pages: Index, Create, Edit in [resources/js/pages/Kinetix/{$pluralName}/]");
        }
    }
}
