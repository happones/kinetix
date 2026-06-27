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
- Adding fields like `TextInput`, `NumberField` (steppers + decimal/percent/currency), `Slider`, `Rating` (stars + half), `PinInput` (OTP), `SlugInput` (auto from a source field), `SignaturePad` (canvas → PNG data URL), `Select`, `Checkbox`, `Toggle`, `DateTimePicker`, `DateRangePicker`, `AddressPicker`, `RichEditor` (WYSIWYG: basic/tiptap/markdown), `Textarea`, `Hidden`, `Radio`, `CheckboxList`, `ColorPicker`, `TagsInput`, `KeyValue`, `Repeater`, or `FileUpload`.
- Structuring layouts: `Grid::make(n)`, `Section::make()` (card), `Fieldset::make()` (bordered legend), `Tabs::make()->tabs([Tab::make()->icon()->schema()])`, `Split::make([...])` (responsive flex row), `Placeholder::make()->content()` (read-only, not a field), `Wizard::make()->steps([Step::make()])` (multi-step — see the `kinetix-wizard` skill). All nest and share `columnSpan()`/`visible()`/`hidden()`.
- Adding Laravel validation rules dynamically to inputs (`required()`, `maxLength()`, `rules()`).
- Using lifecycle hooks (`afterStateHydrated()`, `dehydrateStateUsing()`).

## Documentation

For full details, reference the [Kinetix Forms Documentation](file:///home/happones/Plugins/Php/kinetix/docs/forms.md).

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

```php
public function store(Request $request)
{
    $form = $this->buildForm();

    // Validates inputs using built-in Laravel validator mapping
    $validated = $form->validate($request->all());

    // Retrieve processed state including dehydration transformations
    $state = $form->getState($request->all());

    Post::create($state);

    return redirect()->route('posts.index');
}
```

---

## Best Practices

- **Constructor Property Injection**: Ensure all fields use promotion parameter reflection.
- **Dynamic Closures**: Leverage closures on field configurations (e.g. `disabled(fn() => !auth()->user()->isAdmin())`) to make forms dynamic to active contexts.
- **Select Option Mapping**: Use native Enum reflection mapping directly (e.g. `options(PostStatus::class)`) to easily bind Enums cast on models.
- **Eager Refactoring**: Avoid complex inline HTML attributes, use `extraInputAttributes()` to inject Tailwind classes or custom settings cleanly.
