# Kinetix Resources Complete Reference

Kinetix Resources provide a powerful, class-based CRUD builder system for Laravel applications, heavily inspired by Filament's developer experience. 

Resources map Eloquent database models to cohesive administration panels by grouping corresponding **Tables**, **Forms**, **Routes**, **Controllers**, and **Vue Views** under unified classes.

---

## 1. Class-Based Forms & Tables

Rather than defining long builder chains inline inside your controllers, Kinetix supports class-based abstractions for forms and tables. This keeps controllers clean and allows schemas to be reused across different views, models, and commands.

### 1. Custom Form Class (`buildSchema()`)
To define a standalone form, extend the base `Form` class and implement the `buildSchema()` method.

```php
namespace App\Kinetix\Forms;

use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Components\Textarea;

class PostForm extends Form
{
    protected function buildSchema(): array
    {
        return [
            TextInput::make('title')
                ->required()
                ->maxLength(150),

            Textarea::make('content')
                ->rows(5)
                ->required(),
        ];
    }
}
```

#### Inline Controller Rendering (`render()`)
To serialize this form and pass it to an Inertia page, call the static `render()` helper:
```php
public function edit(Post $post)
{
    return inertia('Posts/Edit', [
        'form' => PostForm::render($post), // Instantiates, hydrates, and serializes to JSON DTO
    ]);
}
```

### 2. Custom Table Class (`buildColumns()`)
To define a standalone table, extend the base `Table` class and override the builder hooks:

```php
namespace App\Kinetix\Tables;

use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Columns\ToggleColumn;
use Happones\Kinetix\Actions\Action;

class PostTable extends Table
{
    protected function buildColumns(): array
    {
        return [
            TextColumn::make('title')->searchable()->sortable(),
            ToggleColumn::make('is_published')->label('Published'),
        ];
    }

    protected function buildRecordActions(): array
    {
        return [
            Action::make('edit')->url(fn ($record) => route('posts.edit', $record)),
        ];
    }
}
```

#### Inline Controller Rendering (`render()`)
```php
public function index()
{
    return inertia('Posts/Index', [
        'table' => PostTable::render(Post::query()), // Resolves searches/filters and outputs JSON DTO
    ]);
}
```

---

## 2. The Resource Class (`Happones\Kinetix\Resources\Resource`)

A Resource class brings your custom forms and tables together under a single schema configuration mapping to an Eloquent model.

```php
namespace App\Kinetix\Resources;

use App\Models\Post;
use Happones\Kinetix\Resources\Resource;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Forms\Components\TextInput;

class PostResource extends Resource
{
    // 1. Associate Eloquent Model
    protected static ?string $model = Post::class;

    // 2. Navigation metadata
    protected static ?string $navigationIcon = 'document-text';
    protected static ?string $navigationLabel = 'Articles';
    protected static int $navigationSort = 1;

    // 3. Register Listing Table Schema
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
            ]);
    }

    // 4. Register Create/Edit Form Schema
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('title')->required(),
            ]);
    }
}
```

---

## 3. The Resource Command (`kinetix:make-resource`)

Kinetix provides an interactive generator command to automatically scaffold resource configurations, controllers, and Vue 3 frontend pages.

```bash
php artisan kinetix:make-resource {ModelName} [options]
```

### Options List

| Option | Description |
|---|---|
| `--simple` | Creates a single-page resource with CRUD modals inside the index page instead of separate views. |
| `--soft-deletes` | Automatically adds soft delete filters (`withTrashed`) and registers restore/force-delete controller actions. |
| `--generate` | Reflects database table column data types to automatically populate the resource's Form and Table schemas. |

### Example CLI Executions
```bash
# Scaffold multi-page CRUD for the Product model, reading the database table
php artisan kinetix:make-resource Product --generate

# Scaffold simple single-page modal CRUD with soft deletes
php artisan kinetix:make-resource Client --simple --soft-deletes --generate
```

---

## 4. Multi-Page vs. Simple Layouts

### 1. Multi-Page Resource (Default)
Generates separate views for listing, creating, and editing records. Recommended for large models with many fields.

#### Generated Directory File Tree
```
├── app/
│   ├── Http/Controllers/Kinetix/
│   │   └── ProductController.php       <-- Resource routes mapping
│   └── Kinetix/Resources/
│       └── ProductResource.php         <-- Central schema configuration
└── resources/js/pages/Kinetix/Products/
    ├── Index.vue                       <-- Listing Grid Table
    ├── Create.vue                      <-- Form container for creation
    └── Edit.vue                        <-- Form container for updates
```

### 2. Simple Resource (`--simple`)
Generates a single index page. Creation and edits are handled via dialog modals/drawers triggered inline from the table toolbar or rows. Ideal for lightweight models (like tags, categories, or statuses).

#### Generated Directory File Tree
```
├── app/
│   ├── Http/Controllers/Kinetix/
│   │   └── ClientController.php        <-- CRUD endpoints
│   └── Kinetix/Resources/
│       └── ClientResource.php          <-- Shared schema
└── resources/js/pages/Kinetix/Clients/
    └── Index.vue                       <-- Table Grid & Form modals combined
```

---

## 5. End-to-End Generated Code Walkthrough

Here is what Kinetix scaffolds when running:
```bash
php artisan kinetix:make-resource Article --generate
```

### 1. The Scaffolded Resource (`app/Kinetix/Resources/ArticleResource.php`)
```php
namespace App\Kinetix\Resources;

use App\Models\Article;
use Happones\Kinetix\Resources\Resource;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Columns\ToggleColumn;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Components\Toggle;
use Happones\Kinetix\Forms\Components\Textarea;

class ArticleResource extends Resource
{
    protected static ?string $model = Article::class;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('slug')->sortable(),
                ToggleColumn::make('is_published')->label('Published'),
            ]);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('title')->required(),
                TextInput::make('slug')->required(),
                Toggle::make('is_published')->default(false),
            ]);
    }
}
```

### 2. The Scaffolded Controller (`app/Http/Controllers/Kinetix/ArticleController.php`)
```php
namespace App\Http\Controllers\Kinetix;

use App\Http\Controllers\Controller;
use App\Kinetix\Resources\ArticleResource;
use App\Models\Article;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Tables\Table;
use Illuminate\Http\Request;

class ArticleController extends Controller
{
    public function index()
    {
        $table = ArticleResource::table(Table::make(Article::query()));

        return inertia('Kinetix/Articles/Index', [
            'table' => $table->toArray(),
        ]);
    }

    public function create()
    {
        $form = ArticleResource::form(Form::make(new Article()))->fill();

        return inertia('Kinetix/Articles/Create', [
            'form' => $form->toArray(),
        ]);
    }

    public function store(Request $request)
    {
        $form = ArticleResource::form(Form::make(new Article()));
        $form->validate($request->all());

        Article::create($form->getState($request->all()));

        return redirect()->route('articles.index')->with('message', 'Record created successfully.');
    }

    public function edit(Article $record)
    {
        $form = ArticleResource::form(Form::make($record))->fill($record);

        return inertia('Kinetix/Articles/Edit', [
            'form' => $form->toArray(),
            'recordId' => $record->getKey(),
        ]);
    }

    public function update(Request $request, Article $record)
    {
        $form = ArticleResource::form(Form::make($record));
        $form->validate($request->all());

        $record->update($form->getState($request->all()));

        return redirect()->route('articles.index')->with('message', 'Record updated successfully.');
    }

    public function destroy(Article $record)
    {
        $record->delete();

        return redirect()->route('articles.index')->with('message', 'Record deleted successfully.');
    }
}
```

### 3. The Scaffolded Listing page (`resources/js/pages/Kinetix/Articles/Index.vue`)
```vue
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
        <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">Articles Directory</h1>
        <p class="text-sm text-neutral-500">Manage database list records.</p>
      </div>

      <button
        @click="router.get('/articles/create')"
        class="inline-flex items-center justify-center rounded-lg text-sm font-semibold h-9 px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white shadow transition-colors"
      >
        New Entry
      </button>
    </div>

    <KinetixTable :table="table" />
  </div>
</template>
```
