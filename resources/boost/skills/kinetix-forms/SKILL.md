---
name: kinetix-forms
description: "Handles dynamic forms builder schemas, layout components (grid/section/fieldset/tabs/split/placeholder), field types (TextInput, Select, Toggle), validation, and lifecycle hooks in Kinetix. Activates when creating, modifying, or rendering form views."
license: MIT
metadata:
  author: happones
---

# Kinetix Forms Development

## When to Apply

Activate this skill when:
- Building input forms for resource pages or action modals.
- Adding fields like `TextInput`, `NumberField` (steppers + decimal/percent/currency), `Slider`, `Rating` (stars + half), `PinInput` (OTP), `SlugInput` (auto from a source field), `SignaturePad` (canvas → PNG data URL), `PhoneInput` (international, country selector + E.164), `Select`, `Checkbox`, `Toggle`, `DateTimePicker`, `DateRangePicker`, `AddressPicker`, `RichEditor` (WYSIWYG: basic/tiptap/markdown), `Textarea`, `Hidden`, `Radio`, `CheckboxList`, `ColorPicker`, `TagsInput`, `KeyValue`, `Repeater`, or `FileUpload`. Date/Month/Week/Range pickers and `NumberField` default their calendar/number locale to the **application locale** (BCP-47); `->locale('fr')` overrides per field.
- Structuring layouts: `Grid::make(n)`, `Section::make()` (card), `Fieldset::make()` (bordered legend), `Tabs::make()->tabs([Tab::make()->icon()->schema()])`, `Split::make([...])` (responsive flex row), `Placeholder::make()->content()` (read-only, not a field), `Wizard::make()->steps([Step::make()])` (multi-step — see the `kinetix-wizard` skill). All nest and share `columnSpan()`/`columnSpanFull()` (Filament-compatible shorthand for `columnSpan('full')`)/`visible()`/`hidden()`.
- Adding Laravel validation rules dynamically to inputs (`required()`, `maxLength()`, `rules()`), custom messages (`validationMessages()`), or attribute names (`validationAttribute()`).
- Validating either **fluently** (`$form->validate()`) or via a **FormRequest** (`KinetixFormRequest` / the `ResolvesKinetixForm` trait) — rules live in the form, never duplicated.
- Enabling **live validation** with Laravel Precognition (`->precognitive()` / `->validationUrl()`).
- Surfacing validation errors inside **Tabs/Wizards** (auto-switch to the offending tab/step, focus the first errored field).
- Using lifecycle hooks (`afterStateHydrated()`, `dehydrateStateUsing()`).

## Documentation

For full details, reference the [Kinetix Forms Documentation](file:///home/happones/Plugins/Php/kinetix/docs/forms.md).

## Localizing labels

Any display string you set is **your app's copy** — wrap it in Laravel's `__()` so
it's translatable: `->label()`, `->placeholder()`, `->helperText()`, section/tab
headings, and `Select`/radio **option labels**
(`TextInput::make('email')->label(__('posts.fields.email'))`). Fields with no
explicit `->label()` are auto-humanized. See the **kinetix-locale** skill.

## Usage Guide

### 1. Form Schema Definition

```php
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Forms\Components\Grid;
use Happones\Kinetix\Forms\Components\Section;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Components\Select;
use App\Models\Post;

$form = Form::make(Post::make())
    ->schema([
        Section::make('Post Metadata')
            ->description('Specify basic post descriptors.')
            ->schema([
                Grid::make(12)->schema([
                    TextInput::make('title')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(8),

                    Select::make('category_id')
                        ->options(Category::pluck('name', 'id')->all())
                        ->columnSpan(4),
                ]),
            ]),
    ]);
```

### 2. Validation & Submission

Two supported paths — pick per endpoint; **rules always live in the form**, never duplicated.

**Fluent** (inline in the controller):

```php
public function store(Request $request)
{
    $form = $this->buildForm();

    $form->validate($request->all());          // rules + messages + attributes
    Post::create($form->getState($request->all()));  // dehydrated state

    return redirect()->route('posts.index');
}
```

**FormRequest bridge** (for `authorize()`, `prepareForValidation()`, Precognition):

```php
use Happones\Kinetix\Forms\Http\KinetixFormRequest;

class StorePostRequest extends KinetixFormRequest
{
    protected function form(): Form { return PostForm::make()->model(Post::class); }
}

// Controller — dehydratedState() = validated + dehydrated (drops saved(false) fields):
public function store(StorePostRequest $request)
{
    Post::create($request->dehydratedState());
}
```

Already extending another base request? `use ResolvesKinetixForm;` + implement `form()` — same API.

### 3. Live Validation (Precognition)

Reuse the FormRequest rules to validate fields as the user types — **no extra npm dependency** (built-in client):

```php
// Route: add the middleware so Precognition has rules to run.
Route::post('/posts', [PostController::class, 'store'])
    ->middleware(\Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class);

// Form: opt in.
PostForm::make()->precognitive();               // validate against the submit URL
PostForm::make()->validationUrl(route('posts.store'), 'post'); // or an explicit endpoint
```

```vue
<KinetixForm :form="postForm" validation-url="/posts" @submit="submit" />
```

### 4. Error Focus in Tabs & Wizards

`KinetixForm` reads Inertia's `errors` automatically (a controller `ValidationException` now renders with no wiring). Errored **tabs/wizard steps** are marked, the form **switches/jumps to the first offending one**, and the **first errored field is focused + scrolled into view** — fully recursive (wizard-in-tab-in-section resolves correctly). Live per-field validation never steals focus from the field being edited.

---

## Best Practices

- **Constructor Property Injection**: Ensure all fields use promotion parameter reflection.
- **Dynamic Closures**: Leverage closures on field configurations (e.g. `disabled(fn() => !auth()->user()->isAdmin())`) to make forms dynamic to active contexts.
- **Select Option Mapping**: Use native Enum reflection mapping directly (e.g. `options(PostStatus::class)`) to easily bind Enums cast on models.
- **Eager Refactoring**: Avoid complex inline HTML attributes, use `extraInputAttributes()` to inject Tailwind classes or custom settings cleanly.
- **Role/permission-gated fields**: use `->authorize(string $ability, mixed $subject = null)` (Gate-based, same shorthand as `Action::authorize()`) alongside `->visible()`/`->hidden()`. Without an explicit `$subject`, a record-dependent ability defers to visible on `create` (no record yet) and is checked normally on `edit`. Unauthorized fields are dropped from validation, hydration, and the serialized payload — never rely on hiding a field client-side only.

## Responsive Layout

Grids are responsive by default with Filament semantics: `Grid::make(2)` /
`->columns(2)` means 2 columns from `lg` up and ONE below — never assume a
fixed column count on mobile. `columns()` and `columnSpan()` accept breakpoint
maps (`['default' => 1, 'sm' => 2, 'xl' => 3]`, keys default/sm/md/lg/xl/2xl,
values carry forward) on Grid/Section/Fieldset/Tab/Step. Breakpoints measure
the FORM's own width (CSS container queries), so forms in modals collapse
correctly; spans clamp to the available columns per breakpoint and can never
overflow. `columnSpan('full')` spans the row at every size.

