---
name: kinetix-infolists
description: "Handles read-only record detail views in Kinetix: entry types (TextEntry, IconEntry, ImageEntry, ColorEntry), layout components (Section, Grid), server-side state resolution, badges, formatting, and conditional visibility. Activates when building or rendering record 'view' / detail pages."
license: MIT
metadata:
  author: happones
---

# Kinetix Infolists Development

## When to Apply

Activate this skill when:
- Building a read-only "View" / "Show" page for a record or resource.
- Displaying record details without editing (the display-only twin of Forms).
- Adding entries like `TextEntry`, `IconEntry`, `ImageEntry`, or `ColorEntry`.
- Grouping details with `Section::make()`, `Grid::make()`, `Fieldset::make()`, or `Tabs::make()->tabs([Tab::make(...)])`.
- Formatting values for display (`badge()`, `date()`, `money()`, `limit()`, `copyable()`). `date()`/`dateTime()` with no argument localize to the app locale (Carbon `isoFormat`, tokens from `config('kinetix.formats')`); `isoDate()`/`isoDateTime()` take explicit tokens; `->locale()` overrides. `money()` formats via intl in the app locale (`$divideBy` for cents).
- Resolving values with `state()` / `formatStateUsing()` callbacks or conditional visibility.

## Documentation

For full details, reference the [Kinetix Infolists Documentation](file:///home/happones/Plugins/Php/kinetix/docs/infolists.md).

## Usage Guide

### 1. Infolist Schema Definition

```php
use Happones\Kinetix\Infolists\Infolist;
use Happones\Kinetix\Infolists\Components\Section;
use Happones\Kinetix\Infolists\Components\TextEntry;
use Happones\Kinetix\Infolists\Components\IconEntry;
use App\Models\User;

$infolist = Infolist::make($user)
    ->schema([
        Section::make('Account')
            ->icon('user')
            ->columns(12)
            ->schema([
                TextEntry::make('name')->columnSpan(6),
                TextEntry::make('email')->icon('mail')->copyable()->columnSpan(6),
                TextEntry::make('status')->badge()
                    ->color(fn ($state) => $state === 'active' ? 'success' : 'gray')
                    ->columnSpan(4),
                IconEntry::make('is_verified')->boolean()->columnSpan(4),
                TextEntry::make('created_at')->dateTime()->columnSpan(4),
            ]),
    ]);
```

### 2. Serialization & Rendering

```php
public function show(User $user)
{
    return inertia('Users/Show', [
        'infolist' => UserResource::infolist(Infolist::make($user))->toArray(),
    ]);
}
```

```vue
<KinetixInfolist :infolist="infolist" />
```

---

## Best Practices

- **Server-side state**: Infolists resolve and format every value in PHP via `getState()`. Never recompute display values in Vue — the frontend renders pre-formatted state only.
- **Enum contracts**: Lean on `HasLabel`, `HasColor`, and `HasIcon` enum contracts so one Enum drives the label, badge color, and icon consistently across Tables and Infolists.
- **Relationship attributes**: Use dot-notation (`TextEntry::make('company.name')`) to read related models without a manual `state()` callback.
- **Conditional closures**: Use `->visible(fn ($record) => ...)` and `->color(fn ($state) => ...)` to keep detail views dynamic; hidden entries are stripped before serialization.
- **Role/permission-gated entries**: use `->authorize(string $ability, mixed $subject = null)` (Gate-based, same shorthand as `Action::authorize()`/Forms) alongside `->visible()`/`->hidden()` for sensitive fields (e.g. `TextEntry::make('salary')->authorize('viewFinancials')`). Unauthorized entries are omitted from the serialized payload entirely.
- **Read-only by design**: Do not add inputs, validation, or two-way bindings to infolists — use Kinetix Forms for editable schemas.
