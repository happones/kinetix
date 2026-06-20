# Kinetix Forms

The Kinetix Forms package allows you to easily build dynamic, validated, and interactive forms in your Laravel app and serialize them for Vue/Inertia.

---

## Basic Form Schema Definition

You can build form definitions using the fluent `Form` and field classes.

```php
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Components\Select;
use Happones\Kinetix\Forms\Components\Toggle;
use Happones\Kinetix\Forms\Components\Textarea;
use App\Models\User;

$form = Form::make()
    ->schema([
        TextInput::make('name')
            ->required()
            ->maxLength(255),

        TextInput::make('email')
            ->email()
            ->required(),

        Select::make('role')
            ->options([
                'admin' => 'Administrator',
                'user' => 'Regular User',
            ])
            ->default('user'),

        Toggle::make('is_active')
            ->label('Account Active'),

        Textarea::make('bio')
            ->rows(3)
            ->placeholder('Tell us about yourself...'),
    ]);
```

---

## Form Fields

Fields can be found in the `Happones\Kinetix\Forms\Components` namespace. Available field types:

1. **`TextInput`**: Text entries. Supports password, email, numeric, and url modes:
   ```php
   TextInput::make('password')->password()
   TextInput::make('age')->numeric()
   ```
2. **`Select`**: Dropdowns. Supports associative arrays, Closures, and Enums:
   ```php
   Select::make('status')->options(PostStatus::class)
   ```
3. **`Checkbox`**: Standard boolean checkboxes.
4. **`Toggle`**: Switch toggles.
5. **`DateTimePicker`**: Date and time pickers.
6. **`Textarea`**: Multiline text entries.
7. **`Hidden`**: Hidden inputs.

---

## Layout Components

Kinetix Forms includes layout components to group and structure fields:

### Grid Layout

Arrange inputs in multi-column rows. The column span defaults to `'full'` (12 columns) but can be set to spans:

```php
use Happones\Kinetix\Forms\Components\Grid;
use Happones\Kinetix\Forms\Components\TextInput;

Grid::make(12)
    ->schema([
        TextInput::make('first_name')->columnSpan(6),
        TextInput::make('last_name')->columnSpan(6),
    ])
```

### Card Section

Group fields under a styled panel card container:

```php
use Happones\Kinetix\Forms\Components\Section;

Section::make('Profile Information')
    ->description('Update your account details.')
    ->schema([
        TextInput::make('display_name'),
    ])
```

---

## Lifecycle Hooks

Fields support hydration/dehydration callbacks:

- **`afterStateHydrated(Closure $callback)`**: Executed when filling the form state.
- **`dehydrateStateUsing(Closure $callback)`**: Executed when reading the form values for submission.
- **`afterStateUpdated(Closure $callback)`**: Callback executed after value updates.

---

## Filling and Submitting Forms

### Hydration (Filling)

You can fill the form with an array or Eloquent model instance:

```php
// Fill with array
$form->fill(['name' => 'John Doe']);

// Fill with Eloquent record (auto-resolves attributes)
$form->fill($user);
```

### Validation

Kinetix Forms maps rules from fields. You can validate the input data directly:

```php
try {
    $validated = $form->validate(request()->all());
} catch (\Illuminate\Validation\ValidationException $e) {
    // Laravel automatically handles this redirecting back with errors
}
```

### Dehydration (Retrieving State)

To fetch the cleaned, dehydrated values:

```php
$data = $form->getState(request()->all());
```

---

## Serialization & Vue Component

To render the form inside your Inertia view, pass the serialized `FormData` DTO:

```php
return inertia('AccountSettings', [
    'profileForm' => $form->toData(),
]);
```
