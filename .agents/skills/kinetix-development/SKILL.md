---
name: kinetix-development
description: Development guide, structure rules, and best practices for Kinetix Notifications, Widgets, Tables, Forms, Infolists, Actions, Import/Export, and Relation Managers.
---

# Kinetix Development Skill

This skill contains the conventions, requirements, and implementation patterns for Kinetix **Notifications**, **Widgets**, **Tables**, **Forms**, **Infolists**, **Actions** (+ confirmation modals, authorization, bulk), **Import/Export**, and **Relation Managers**. Refer to this guide whenever creating new features or refactoring components.

---

## 1. General Implementation Conventions

### PHP Rules
- **Explicit Types**: Use explicit return type declarations and parameter hints for all methods: `function isFeatured(Model $record): bool`.
- **Property Promotion**: Use PHP 8 constructor property promotion for dependency injection.
- **TitleCase Enum Keys**: Always write Enum keys in TitleCase.
- **Pint Formatter**: Always format modified PHP files before finishing changes.
- **Spatie Data DTOs**: Use classes extending `Spatie\LaravelData\Data` to represent any structured data sent to Inertia/frontend. Annotate these classes with `#[TypeScript]` to enable automatic TypeScript type generation.

### Vue & TypeScript Rules
- **TypeScript First**: Always develop Vue components with `<script setup lang="ts">`.
- **No Inline Types**: Place all TypeScript interfaces and types in `resources/js/types/index.ts`.
- **Flat Logic**: Avoid `else` or `else if` statements in script setups. Use early returns (`if (condition) { return; }`) to keep logic clean and readable.
- **Relative Sibling Imports**: Import sibling Vue components relatively (`./Sibling.vue`) to ensure paths do not break when published.
- **Reka / shadcn only — NEVER native, NEVER invented styles (REQUIRED, no exceptions)**: Every control and every style traces back to Reka UI + shadcn-vue **new-york-v4** (reference: https://github.com/unovue/shadcn-vue/tree/dev/apps/v4/registry/new-york-v4/ui). Do not hand-roll widgets or class strings, and do not invent spacing/colors/shadows/variants — port shadcn's exactly.
  - **Interactive widgets → the Kinetix Reka primitive, never a native element**: `<select>` → `<KinetixSelect>` (Reka `SelectRoot`); `<input type="checkbox">` → `<KinetixCheckbox>`; radios → `<KinetixRadioGroup>`; switch/toggle → Reka `SwitchRoot`; date/time → `KinetixDatePicker`/`KinetixDateTimePicker`/`KinetixRangeCalendar`; **form field labels → `<KinetixLabel>`** (Reka `Label`, shadcn new-york-v4) — not a raw `<label>` with hand-written classes (a `<label>` that *wraps* a control as a clickable option row may stay native). NEVER a raw `<select>`, `<input type="checkbox|radio">`, or a hand-built dropdown/menu/dialog — build accessible widgets on Reka, never on the consumer's copied `@/components/ui/*`.
  - **Align by matching control heights**: every shadcn new-york-v4 control is `h-9` (`inputClass`, `KinetixSelect` trigger, `buttonVariants()` default) — `size: 'sm'` buttons are `h-8`. In a label+field+button row, use the default (`h-9`) button and `items-end` so a label-less button bottom- *and* top-aligns with the inputs; don't mix `h-8` next to `h-9` and don't nudge with margins.
  - **Text input / textarea / button / badge → native element + the canonical helper, never bespoke classes**: `inputClass` / `textareaClass` / `buttonVariants()` / `badgeVariants()` from `@/composables/useShadcnVariants`. Status badges/text/buttons → `useStatusColor` helpers (`statusBadgeClass`, …). There is NO `KinetixButton`/`KinetixInput` (settled decision) — buttons/inputs are the token class strings above. Do not write `class="rounded-md border px-3 py-2 …"` by hand for a field, button, badge, or card; reuse the helper / `components/primitives/*` (Card, ScrollArea).
  - **Tokens only**: style with shadcn semantic tokens (`bg-background`/`text-foreground`, `bg-card`, `bg-primary`, `border-input`, `ring-ring`, `bg-destructive`, …); never raw palette classes (`neutral-*`/`emerald-*`/…) and never literal hex/rgb (except the intentional chart categorical palette). The v4 focus/shape token set is mandatory on every interactive control (`focus-visible:ring-ring/50 focus-visible:ring-[3px]`, `shadow-xs`, etc. — `ring-[3px]`, never `ring-3`).
  - **If a primitive is missing, add it under `components/` (Reka) or `components/primitives/` (parity), mirroring new-york-v4 — do not fall back to a native control or ad-hoc styles.** (See the `KinetixMember*` provisioning components for the canonical pattern: `KinetixSelect` + `inputClass` + `buttonVariants` + `statusBadgeClass`, zero hand-rolled markup.)
- **Pure-CSS Grid Variables**: To prevent Tailwind class purging, map responsive grid spans to inline CSS variables (e.g. `--col-span-md`) and resolve them inside `<style scoped>` media queries.
- **Shadcn Checkboxes**: Always use the custom `<KinetixCheckbox>` component instead of raw HTML `<input type="checkbox">` to maintain the Shadcn design aesthetic across filters, column toggles, and editable table columns.
- **shadcn design tokens (REQUIRED)**: Style with shadcn's semantic token classes — `bg-background`/`text-foreground`, `bg-card`, `bg-popover`/`text-popover-foreground`, `bg-primary`/`text-primary-foreground`, `bg-muted`/`text-muted-foreground`, `bg-accent`, `border-border`/`border-input`, `ring-ring`, `bg-destructive`, `rounded-md` (→`--radius`). Do NOT hardcode `neutral-*`/`emerald-*`/`rose-*`/`amber-*`/`sky-*` raw palette classes — they ignore the host app's theme/dark mode. (All components are token-based; a fallback token CSS ships under `--tag=kinetix-styles`.)
- **Status colors → tokens (REQUIRED)**: Map `success`/`danger`/`warning`/`info`/`primary` via the shared `@/composables/useStatusColor` helpers — `statusBadgeClass` (tinted badge), `statusTextClass` (text/icon), `statusInteractiveTextClass` (text + `focus:` variant), `statusSoftClass` (text on tinted bg), `statusButtonClass` (solid filled button). `danger`→`destructive`; `success`/`warning`/`info` are Kinetix tokens shipped in `kinetix.css` (light+dark, overridable). NEVER interpolate class names (`focus:${x}`) — Tailwind JIT only sees static literals, so keep all class strings literal inside the util. Chart series use an intentional categorical hex palette (not status), leave it.
- **Interactive primitives via Reka UI**: Build accessible widgets on Reka UI (the headless lib shadcn-vue wraps), never by importing the consumer's copied `@/components/ui/*` files (per-app, unguaranteed, break builds). In use: `KinetixCheckbox` (CheckboxRoot), Forms toggle + the table `toggle-input` cell (SwitchRoot / manual button), `KinetixSelect` (SelectRoot), `KinetixRadioGroup` (RadioGroupRoot), `KinetixRangeCalendar` (RangeCalendarRoot), `KinetixLabel` (Label). `reka-ui` + `@internationalized/date` are optional peer deps.
- **new-york-v4 focus/shape tokens (REQUIRED on every interactive control)**: shadcn-vue new-york-v4 dropped the v3 focus ring (`focus-visible:ring-2 ring-ring ring-offset-2` / `focus:ring-1`) — use the v4 set: `outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]` + `aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40`, `shadow-xs` (not `shadow-sm`), `transition-[color,box-shadow]`/`transition-shadow`, and `dark:bg-input/30` on bordered inputs. **The token is `ring-[3px]`, NOT `ring-3`** (`ring-3` is not on Tailwind v4's ring scale → emits no ring). Switch v4: `h-[1.15rem] w-8` track + `size-4` thumb + `data-[state=checked]:translate-x-[calc(100%-2px)]` + `dark:data-[state=unchecked]:bg-input/80` / `dark:data-[state=*]:bg-foreground|primary-foreground` thumb. Checkbox `rounded-[4px] size-4` + icon `size-3.5`. Select item: check indicator on the RIGHT (`pr-8 pl-2`, `right-2`). Don't hardcode thumb colors (`bg-white`) — use `bg-background`/token.
- **Non-Reka elements via parity primitives (REQUIRED)**: For Card/Button/Badge/Input/ScrollArea (built "from scratch", not Reka), use Kinetix's own primitives that mirror shadcn-vue **new-york-v4** EXACTLY — Card family + `ScrollArea`/`ScrollBar` (Reka ScrollArea*) in `components/primitives/*` (Card v4 structure: `flex flex-col gap-6 py-6` card + `px-6` sections + `data-slot`; use `cn` from `./primitives/cn`), and `composables/useShadcnVariants` (`buttonVariants`/`badgeVariants`/`inputClass`/`textareaClass`). The DateTimePicker time columns use `ScrollArea`. Import these via RELATIVE paths (`./primitives/Card.vue`) — `@/components/ui/*` would resolve to the CONSUMER's files post-publish. Do NOT re-hand-roll `rounded-xl border ... p-6` card markup; reuse the primitives. shadcn Badge is a `rounded-full` pill; Kinetix soft status badges (`useStatusColor`) are a separate Filament-style element. No new deps (`cn` is a local 4-line join, not clsx/tailwind-merge). **Settled decision (do not revisit): Kinetix is self-contained — it does NOT import or re-publish the host's `@/components/ui` shadcn-vue files, and it does NOT ship a parallel public UI kit (`KinetixButton` etc. do not exist; buttons/badges/inputs are token class strings).** shadcn-vue is copy-paste (not an npm pkg): the starter kit ships only a SUBSET, with variant/version drift, so importing host ui = hard build failures for the consumer. We reuse shadcn's *foundation* — the same tokens (→ pixel-identical look + dark mode) and the same headless lib (Reka UI, a real declared peer dep) — not its copied files. The `primitives/` are an internal implementation detail, not "the UI".

### Localization & Documentation Rules
- **Translations (i18n)**: Never hardcode user-facing text strings inside Vue components. Always load them using the Vue-i18n `t()` helper under the `kinetix` namespace (e.g. `t('kinetix.key_name')`).
- **Language PHP Files**: Define matching translation key/value pairs in all language folders under `resources/lang/` (`en`, `es`, `fr`, `pt`).
- **Sync Documentation**: Whenever new options, components, or configurations are created, always update the relevant markdown files in `docs/` and workspace development skills.
- **Mandatory Translation & Documentation Checklist**: Whenever creating new components, modules, or features, you **MUST** ensure all corresponding documentation (in `docs/`) and translations (in `resources/lang/` for English, Spanish, French, and Portuguese) are fully written, synchronized, and completed. Do not leave placeholder text or skip translation file updates.
- **Boost Skill Per Feature (REQUIRED)**: Every new feature/module MUST also ship a consumer-facing Laravel Boost skill at `resources/boost/skills/kinetix-<feature>/SKILL.md` (Laravel Boost auto-discovers that directory — no manifest to edit). Mirror an existing one (e.g. `kinetix-permissions` / `kinetix-membership`): frontmatter `name` + a `description` listing concrete activation triggers, then config, backend usage, frontend usage, and a link to the `docs/<feature>.md` page. Add a matching numbered section to THIS development skill in the same change. A feature is not "done" until both skills exist alongside the `docs/` page and translations.

### Versioning & Releases
- **Semantic Versioning (REQUIRED)**: this package follows [SemVer](https://semver.org) (`MAJOR.MINOR.PATCH`). Bug fixes → PATCH, backward-compatible features → MINOR, breaking changes → MAJOR (pre-1.0, breaking changes may land in MINOR). Releases are git tags; `composer.json` carries no hardcoded version.
- **Changelog**: every change gets a `CHANGELOG.md` entry (Keep a Changelog). Mark **(published)** anything that changes files consumers receive via `vendor:publish` (components, stores, types, lang, config). Do not mutate an already-published version; cut a new one.

### Test Layout
- **Frontend specs live in `tests/js/`** (NOT under `resources/js/`), so `vendor:publish` never copies test files into a consumer's app. Vitest `include` points at `tests/js/**`; specs import source via the `@` alias (`@/components/...`, `@/composables/...`, `@/stores/...`). PHP tests live in `tests/` (Testbench). Keep all four suites green: `testbench package:test`, `phpstan`, `pint --test`, `vitest` + `vue-tsc`.

---

## 2. Kinetix Notifications

Kinetix Notifications provide backend-to-frontend notifications using Laravel Echo/Reverb and Inertia.

### Backend Dispatching
```php
use Happones\Kinetix\Notifications\Notification;
use Happones\Kinetix\Actions\Action;

Notification::make()
    ->title('Order Shipped')
    ->description('Your order #1084 has been successfully shipped.')
    ->success() // success, info, warning, danger
    ->actions([
        Action::make('track')
            ->label('Track Package')
            ->url('/orders/1084/track')
            ->button()
            ->color('primary')
            ->close(),
        
        Action::make('dismiss')
            ->label('Dismiss')
            ->link()
            ->color('gray')
            ->close(),
    ])
    ->broadcast($user);
```

### Key Frontend Components
- `KinetixNotificationTrigger.vue`: Renders the bell trigger and unread counter badge.
- `KinetixNotificationDrawer.vue`: The sliding sheet sidebar listing all database notifications.
- `KinetixNotificationItem.vue`: Individual notification item managing action dispatchers.

### Toasts
- Toasts use `vue-sonner` (store `triggerToast` → `toast.success/warning/error/info`); mount **`<KinetixToaster />`** once, and REMOVE any other `<Toaster>` (vue-sonner has ONE global queue → every mounted Toaster renders every toast, so a leftover raw Toaster repaints it light). KinetixToaster fixes dark-mode contrast by **redefining the CSS vars vue-sonner reads** via `style` (`--normal-bg`/`--normal-text`/`--normal-border` → `--popover`/`--popover-foreground`/`--border`), NOT by class overrides — class overrides (`group-[.toaster]:bg-background`) lose the specificity war vs `[data-sonner-toast]` when `vue-sonner/style.css` loads after Tailwind (→ stubborn white toast under `.dark`). Mirrors shadcn-vue new-york-v4 Sonner exactly. Forwards all Toaster props.

### Broadcasting system notifications
- System notifications (export/import done) broadcast in real time when **`Notification::shouldBroadcast()`** is true = `kinetix.notifications.broadcast` (env `KINETIX_NOTIFICATIONS_BROADCAST`) **OR** the `kinetix.broadcasting.echo` block is set. When true the recipient is notified via channels `['database','broadcast']` (persisted AND pushed); otherwise database-only. Use the dedicated `notifications.broadcast` flag if you wired Echo/Reverb yourself (don't tie it to the kinetix echo frontend block). Real-time delivery also needs: server `BROADCAST_CONNECTION` set (reverb/pusher), the queue worker running (the Laravel notification is `ShouldQueue`), and `<KinetixNotifications />` mounted (it subscribes via `@laravel/echo-vue` to `private-App.Models.User.{id}`).

---

## 3. Kinetix Widgets

Widgets are modular metric layout blocks mapped to Inertia views.

### Backend Layout
```php
use Happones\Kinetix\Widgets\WidgetsGrid;
use Happones\Kinetix\Widgets\StatsOverviewWidget;
use Happones\Kinetix\Widgets\Stats\Stat;
use Happones\Kinetix\Widgets\ChartWidget;

$grid = WidgetsGrid::make()
    ->columns(['default' => 12, 'lg' => 3])
    ->widgets([
        StatsOverviewWidget::make()
            ->stats([
                Stat::make('Active Users', 1240)
                    ->description('3% increase')
                    ->descriptionIcon('trending-up')
                    ->descriptionColor('success')
                    ->chart([10, 12, 14, 11, 16]),
            ]),

        ChartWidget::make()
            ->chartType('line') // line, bar, pie, doughnut
            ->labels(['Jan', 'Feb', 'Mar'])
            ->datasets([
                ['label' => 'Revenue', 'data' => [400, 500, 480]]
            ])
    ]);
```

### Visual Sparklines
- Stats sparklines are drawn dynamically as **lightweight SVG paths** with gradient fills mapped to the status color (`success` = green, `danger` = red).

### Charting System (Unovis)
- All charts are rendered using **`@unovis/vue`** and **`@unovis/ts`** (the framework behind Shadcn Vue charts).
- **CRITICAL**: For line and bar XY charts, always map string labels to numeric indices in the data coordinates (`0, 1, 2, ...`). Use the `:tickValues` array to force ticks onto index coordinates and format them back to strings using `:tickFormat`. This prevents continuous scale `NaN` rendering exceptions in Unovis.

---

## 4. Kinetix Tables

Tables provide Eloquent query filtering, pagination, and inline database updates.

### Schema Definition
```php
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Columns\ToggleColumn;
use Happones\Kinetix\Tables\Filters\SelectFilter;
use App\Models\User;

$table = Table::make(User::query())
    ->heading('Users Directory')
    ->striped()
    ->columns([
        TextColumn::make('name')->searchable()->sortable(),
        TextColumn::make('email')->searchable(),
        ToggleColumn::make('is_active')->label('Status'),
    ])
    ->filters([
        SelectFilter::make('role')->options(['admin' => 'Admin', 'user' => 'User']),
    ]);
```

### Column Types
- `TextColumn`: String grids, badges, custom carbon date formats, currency formats, and text truncation.
- `IconColumn`: Boolean checks and conditional icon statuses.
- `ImageColumn`: Thumbnail previews with sizing and circular shape options. `->preview()` opens a zoomable lightbox. `->disk('s3')` (or the global `kinetix.filesystem.disk`, default `public`) resolves stored paths to URLs via `Storage::disk()->url()` in `Table::formatRecord()`; absolute URLs pass through. **Filesystem**: `config('kinetix.filesystem.disk')` (default `public`) is the global disk for EVERYTHING that stores/serves files — `FileUpload` (uploads), `ImageColumn`/`ImageEntry` (asset URLs), and `ExportProcessor`/`ImportController`/`ImportProcessor` (export artifacts + import temp files). Per-instance `->disk()` overrides on the components. Resolve the disk + bridge cloud disks to a local path via `Happones\Kinetix\Support\KinetixDisk` (`name()`, `localReadablePath()`, `discardTemp()`) — CSV/XLSX read/write need a real local path, so s3 etc. stream to a temp file. Exports write to a temp file then `putFileAs` on the disk; the download token carries the disk.
- `ColorColumn`: Color swatches supporting one-click clipboard copying.
- **Inline Editors**: `SelectColumn`, `ToggleColumn`, `TextInputColumn`, and `CheckboxColumn` provide live database modifications (each overrides `isEditable()` → true).

### Filters
- `Filter` (checkbox + custom `query()`), `SelectFilter` (`options()` accepts an Enum class), `MultiSelectFilter` (checkbox list → `whereIn`), `TernaryFilter` (All/true/false for booleans; `trueLabel`/`falseLabel`/`queries()`), `DateFilter` (single date, `operator()` default `=`), `DateTimeFilter` (single datetime, default `>=`), `DateRangeFilter` (`{from,to}` → `whereDate`), `NumberRangeFilter` (`{min,max}`), `TrashedFilter` (SoftDeletes: blank=active, `with`→`withTrashed()`, `only`→`onlyTrashed()`).
- **Date filters default to the shadcn calendar** (Reka), NOT native inputs. `DateFilter`→`KinetixDatePicker`, `DateTimeFilter`→`KinetixDateTimePicker` (calendar + scrollable hour/minute button columns; `->twelveHour()` adds an AM/PM column; `->minuteStep()`), `DateRangeFilter`→`KinetixRangeCalendar` (`->months()/->weekdayFormat()/->fixedWeeks()/->minValue()/->maxValue()`). Call **`->native()`** on any of them to fall back to the plain native `<input>`. `->locale()` sets the BCP-47 calendar locale.
- Range/multi filters submit structured values (object/array); the active-filter loop passes them to `apply()` which guards empty selections. New filter types need a render branch in `KinetixTable.vue` + a `type` in `KinetixTableFilter`.

### Action sections (header / footer / bulk)
- **`Table::toolbarActions([...])`** (alias **`headerActions()`**) → top toolbar, next to search/filters. **This is where table-level Import/Export buttons go** (e.g. an `Action` that `->inertiaVisit(route('...export'))` or `->dispatch('open-importer')` to open a `<KinetixImporter>`). Renders solid primary buttons via `primaryActionClass`.
- **`Table::footerActions([...])`** → a bar below the table (next to pagination), e.g. "Export all". Serialized as `TableData.footerActions`; rendered like the toolbar.
- **Both contexts**: place the SAME `Action` in `toolbarActions`/`footerActions` AND `bulkActions` — toolbar/footer act on the whole (filtered) table, bulk merges the selected `ids` into the payload. So one Export action covers "export all" and "export selected".

### Bulk Actions & Query Prefix
- `Table::bulkActions([Action,...])` enables row selection (select-all + per-row checkbox) + a bulk bar; selected ids are sent (`inertiaVisit`→request `ids`; `dispatch`→`e.detail.ids`). Confirmation gated by a second confirm modal.
- `Table::queryPrefix('posts_')` namespaces query-string params (`posts_search`/`posts_page`/…) so multiple tables (e.g. relation managers) coexist; `KinetixTable.vue` preserves foreign params on reload.
- `Table::stickyActions()` (optional, default off) pins the record-actions column to the right edge (`sticky right-0` on header th `bg-muted` + body td `bg-card` + `border-l`) so actions stay visible during horizontal scroll. Serialized as `TableData.stickyActions`.

### Security & Cell Updates
- In-table edits trigger XHR requests to `{prefix}/tables/cell-update`.
- To prevent parameter tampering, the table's `model` token encrypts BOTH the model class AND the list of **editable column names** (`Crypt::encrypt(['model'=>…,'columns'=>[…]])`). The controller decrypts it, validates the model class, and **returns 403 unless the requested column is in the editable list** — so a user cannot write arbitrary columns (e.g. `is_admin`). Keep this guarantee when changing inline editing.

---

## 5. Kinetix Forms

Forms are fluent form schema builders that resolve validation, hydration, dehydration, and serialization.

### Backend Layout & Schema
```php
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Forms\Components\Grid;
use Happones\Kinetix\Forms\Components\Section;
use Happones\Kinetix\Forms\Components\TextInput;

$form = Form::make($record)
    ->schema([
        Section::make('Basic Details')
            ->schema([
                Grid::make(12)->schema([
                    TextInput::make('first_name')->columnSpan(6)->required(),
                    TextInput::make('last_name')->columnSpan(6)->required(),
                ]),
            ]),
    ]);
```

### Components & Fields Architecture
- **Base Components**: All form elements extend `Component`, managing column spans and operation visibility constraints (`hiddenOn`, `visibleOn`).
- **Base Fields**: All actual input controls extend `Field`, inheriting validation rules (`rules()`, `required()`, `maxLength()`), defaultValue configurations, and hydration/dehydration callbacks.
- **Available Fields**: `TextInput`, `Textarea`, `Select`, `Checkbox`, `Toggle`, `DatePicker`, `DateTimePicker`, `Hidden`, `Radio`, `CheckboxList`, `ColorPicker`, `TagsInput`, `KeyValue`, `Repeater`, `FileUpload`.
- **DatePicker / DateTimePicker default to the shadcn calendar** (`KinetixDatePicker` / `KinetixDateTimePicker`), NOT native inputs. `->native()` opts back to native `<input>`; `->locale()`, `DateTimePicker->minuteStep()`/`->twelveHour()` (AM/PM column). Inputs/textareas/buttons across forms reuse `inputClass`/`textareaClass`/`buttonVariants` from `@/composables/useShadcnVariants` — don't re-hand-roll field/button class strings.
- **Select-derived**: `Radio` and `CheckboxList` extend `Select` to reuse `options()` (incl. Enum reflection). `CheckboxList` stores an **array** (pair with an `array` cast). Both support `inline()`.
- **Array/Object fields**: `TagsInput` (string array) and `KeyValue` (object) keep ephemeral edit state in their own components (`KinetixTagsInput`/`KinetixKeyValue`) to avoid leaks. `Repeater` repeats a `schema()` over an array-of-objects (`minItems`/`maxItems`/`addActionLabel`; rendered by recursing `KinetixFormSchema` per item; extends `Field` so validation treats it as one array field).
- **FileUpload**: stores path(s); uploads via `{prefix}/uploads/store` (+ `uploads/delete`). Storage config (disk/dir/constraints) is signed into an encrypted `uploadToken` and re-validated server-side — the client can't target arbitrary disks. State in `KinetixFileUpload`.
- **Field-specific serialization**: override `toData()`, call `parent::toData()`, then mutate the returned `FormFieldData` (e.g. `$data->inputType`, `$data->isInline`) — mirror `TextInput`.
- **Select Fields**: `options()` accepts array, `Closure`, or a `UnitEnum` class (auto value→label). Rendered by `KinetixSelect` (Reka `SelectRoot`); `Radio` by `KinetixRadioGroup` (Reka), `Toggle` by Reka `SwitchRoot`, `Checkbox` by `KinetixCheckbox` (Reka).

---

## 6. Kinetix Infolists

Read-only, schema-driven record display — the display twin of Forms. State is resolved/formatted server-side; the Vue layer (`KinetixInfolist` → recursive `KinetixInfolistEntries`) is stateless (no watchers/leaks).

```php
use Happones\Kinetix\Infolists\Infolist;
use Happones\Kinetix\Infolists\Components\{Section, TextEntry, IconEntry};

Infolist::make($user)->schema([
    Section::make('Account')->icon('user')->columns(12)->schema([
        TextEntry::make('email')->icon('mail')->copyable()->columnSpan(6),
        TextEntry::make('status')->badge()->color(fn ($s) => $s === 'active' ? 'success' : 'gray'),
        IconEntry::make('is_verified')->boolean(),
        TextEntry::make('created_at')->dateTime(),
    ]),
]);
```

- **Entries**: `TextEntry` (badge/date/dateTime/money/limit/copyable/url/inlineLabel), `IconEntry` (boolean / options/colors maps), `ImageEntry` (circular/square/size/defaultImageUrl), `ColorEntry` (copyable hex). Base `Entry` resolves via `getRawState()` (dot-notation + `state()` + `default()`) → `formatStateUsing()` → type formatting; honours `HasLabel`/`HasColor`/`HasIcon` enum contracts.
- **Layouts**: `Section` (titled card), `Grid`, `Fieldset` (labelled bordered group), `Tabs`/`Tab` (switchable panels; active tab tracked client-side per instance, hidden tabs stripped on serialize).
- **Resource hook**: `Resource::infolist(Infolist $infolist)` parallels `form()`/`table()`.

---

## 7. Kinetix Actions, Authorization & Bulk

The fluent `Actions\Action` builder powers notification buttons, table record/toolbar/bulk actions, and page headers.

- **Prebuilt CRUD actions**: `ViewAction`/`EditAction`/`DeleteAction` (record actions; default policy abilities `view`/`update`/`delete`; Delete also `requiresConfirmation()`+danger), `CreateAction` (toolbar; pass `->authorize('create', Model::class)`), `RestoreAction`/`ForceDeleteAction` (SoftDeletes; abilities `restore`/`forceDelete`, visible only when `$record->trashed()`).
- **`->request()` vs `->inertiaVisit()`**: `->inertiaVisit($url,['method'=>'post'])` does a `router.visit()` and REQUIRES the endpoint to return an Inertia response (redirect/render) — a JSON response pops Inertia's error modal. For fire-and-forget JSON endpoints (queue + notify), use **`->request($url,['method'=>'post','toast'=>trans('…')])`** → plain `fetch()` (XSRF token added) + a success toast, no navigation. `executeAction` handles both; bulk `ids`/extraData go in the request body.
- **Export/Import actions (prebuilt, Filament-style)**: `ExportAction::make()->exporter(ProductExporter::class)` (uses `->request()` → "Export queued" toast, then a download notification when done) — POSTs the exporter token to the built-in `kinetix.exports.start` endpoint which dispatches the queued export; in `toolbarActions`/`headerActions` it exports the exporter's `query()`, in `bulkActions` it sends the selected `ids` (scope with `query()->when($this->parameter('ids'), …)`). `ImportAction::make()->importer(ProductImporter::class)` — dispatches `kinetix:open-importer` (importer token); mount the global `<KinetixImportModal />` once to show `KinetixImporter` in a dialog. Both are normal Actions (label/icon/color/authorize). Exporter/Importer expose `token()`/`fromToken()`.
- **File actions**: `DownloadAction` (forces a browser download of `->url(...)`; `Action::download()` is the underlying flag) and `PreviewAction` (`->url(...)` opens in the global file-preview lightbox — image/pdf, `->preview('image'|'pdf'|'auto')` / `Action::preview($type)`). Both customizable (`->color()`/`->icon()`/`->label()`). They route the resolved per-row URL via `executeAction` (download = synthetic `<a download>` click; preview = `kinetix:preview` event). Mount `<KinetixFilePreview />` once in the layout (like the notification components) for image-column previews and PreviewActions to work. PDFs render **inline** via `<object type="application/pdf">` + `<iframe>` fallback (never external).
- **Action icons & colors**: all prebuilt actions ship a default icon + color (edit/gray, view/gray, delete=trash/danger, create=plus/primary, restore=rotate-ccw/gray, forceDelete=trash-2/danger, download/gray, preview=eye/gray). Override with `->icon('name')`/`->color('...')`, or **disable the icon with `->icon(null)`**. Icons resolve through the SHARED `@/composables/useKinetixIcons` `resolveIcon()` map (single source for table/dropdown/page-header) — add new lucide names there, not per-component, to avoid "missing icon" drift.
- **Route binding**: `->url(fn ($record) => route('posts.edit', $record))` resolves via Laravel `getRouteKey()`, so custom `getRouteKeyName()` (slug, uuid) is respected — the full model is handed to the closure. The row `id` (`getKey()`/PK) is only used for bulk/cell-update `whereKey`.
- **Confirmation modals**: `requiresConfirmation(bool|string)` + `modalHeading()/modalDescription()/modalIcon()/modalSubmitActionLabel()/modalCancelActionLabel()` → serialized on `ActionData`; rendered by leak-safe `KinetixConfirmModal.vue` (Escape/overlay close, listener torn down on unmount).
- **Authorization (server-side, secure)**: `Action`/`ActionGroup` use the `HasAuthorization` trait — `->authorize(string $ability, $subject = null)` (`Gate::allows`, subject defaults to the record), `->authorize(Closure|bool)`, `->visible()`/`->hidden()`. `toData()` returns **null** when not visible/authorized; `Table` filters those out (definitions + per-row). **Unauthorized actions are NEVER serialized to the client** — don't rely on client-side hiding. Record-action templates with no row defer string-ability checks to the per-row pass. For manual contexts use `Action::toArrayMany([...], $record)`.
- **Action groups**: `ActionGroup::make([Action,...])` → `ActionData` `type:'group'` + nested `actions` (unauthorized children dropped); rendered by `KinetixActionDropdown.vue`.
- **Execution composable**: `@/composables/useKinetixActions` (`executeAction(action, extraData?)` + `useActionConfirmation()`) is shared by table, page header, and dropdown — route new action UIs through it.
- **Page action bars**: `KinetixPageHeader.vue` (title + description + actions row). **Bulk**: see §4.

---

## 8. Kinetix Import / Export

Queue-backed CSV/Excel import (smart mapping preview) + export (download notification). Excel via `phpoffice/phpspreadsheet`; CSV native.

- **Import**: extend `Importer` (`getColumns()` of `ImportColumn`, `$model`, `resolveRecord()` for upsert, `importRow()`, `chunkSize()`/`queue()`). `Importer::guessMapping($headers)` auto-maps headers→columns (normalized, **collision-free**). Endpoints `imports/upload|preview|start` (importer class + stored file are encrypted tokens). `ImportProcessor` (ShouldQueue) maps by header index, validates column `rules()`, chunked transactions, deletes temp file, sends a completion notification. UI: `KinetixImporter.vue` (CSV options + mapping `<select>` per target, auto-selected + collision-disabled, preview, start gated on required).
- **Export**: extend `Exporter` (`getColumns()` of `ExportColumn`, `$model`/`query()`, `format()` csv|xlsx, `chunkSize()`, `export(?Model $recipient)`). `ExportProcessor` (ShouldQueue) streams via `FileWriter`, then sends an **"Export ready" notification with a signed Download action**; `kinetix.exports.download` (token-guarded, no team prefix).
- **Generators**: `kinetix:make-importer`, `kinetix:make-exporter`. Docs: [docs/import-export.md].

---

## 9. Kinetix Relation Managers

Manage a parent record's related records on its edit/show page; a thin composition over `Table`.

```php
use Happones\Kinetix\Resources\RelationManager;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tables\Columns\TextColumn;

class PostsRelationManager extends RelationManager
{
    protected static string $relationship = 'posts';
    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')->searchable()]);
    }
}
// Page: ['relations' => [PostsRelationManager::make($user)->toArray()]] → <KinetixRelationManager :manager="r" />
```

- Scoping: `getRelationshipQuery()` = `$parent->{relationship}()->getQuery()` (parent FK constraints auto-applied). The table is given `queryPrefix("{relationship}_")`. CRUD via ordinary `Action`s pointing at your routes. `Resource::relationManagers(): array` lists the managers. **Per-page visibility**: `RelationManager::$visibleOn = ['edit','view']` (default both) + overridable static `isVisibleOn($page)` (for per-record logic, the `canViewForRecord` analogue); `Resource::relationManagersFor($page)` returns only the managers visible on that page — build each edit/view page's list with it, not raw `relationManagers()`. Serializes to `RelationManagerData {title, relationship, table}`; rendered by `KinetixRelationManager.vue`.

---

## 10. Kinetix Billing (optional, Cashier + Stripe)

Optional module wrapping Laravel Cashier. **Off by default** (`kinetix.billing.enabled`); Cashier is a *suggested* dep — guard every Cashier call (`method_exists`), since the `Billable`/`HasPlan` traits live on the host's configurable billable.

```php
use Happones\Kinetix\Billing\Plan;
use Happones\Kinetix\Billing\BillingManager;

$user->canUseFeature('capabilities.api');                 // HasPlan on the billable; dot-path features
BillingManager::for($user)->subscribe('pro', $pm, 'monthly'); // free=downgrade, paid=swap/create
Route::post('/x', ...)->middleware('plan.feature:capabilities.api');
```

- `Plan` (dot-path JSON `features`: `canUseFeature`/`hasReachedLimit`(null=unlimited)/`priceFor`/`stripePriceId`/`isFree`). `BillingManager` = Cashier orchestration; `BillingController`+`BillingRoutes::register()` = zero-config endpoints. `PlanFeatureMiddleware` = `plan.feature:dotpath`.
- Vue (token-only; UI labels via `t('kinetix.billing_*')`, data via props): `KinetixPricingTable`/`KinetixPlanCard` (capability rows from a `featureLabels` dot-path map — never hardcode app keys), `KinetixPaymentMethods`, `KinetixSubscriptionStatus`, `KinetixInvoicesTable`. `useKinetixBilling(endpoints)` = Inertia visits; `useKinetixStripe` = Stripe Elements styled from shadcn tokens resolved to `rgb()`, re-themed on `<html>` toggle, leak-safe teardown (verify light+dark). ALL Kinetix Vue components use vue-i18n `t('kinetix.*')` for fixed UI text (no English-default label props) — keys live in `resources/lang/*/kinetix.php` (en/es/fr/pt), tests inject the shared `__tests__/i18n.ts` plugin.
- Scaffold: `php artisan kinetix:make-billing --seeder`. Full guide: `docs/billing.md`.

---

## 11. Kinetix Permissions (optional, Spatie Laravel Permission)

Feature-scoped roles and permissions integrated with `spatie/laravel-permission`. Enforcement flows through Laravel's Gate; this system adds sync commands, super-admin bypasses, and multi-tenant bridging.

- **Registry**: Central registry `PermissionRegistry` maps features and resource classes. Register resources via `KinetixPermissions::resource(...)` or features via `KinetixPermissions::feature('name')`.
- **Resource Integration**: Define `permissionFeature()` on the resource to auto-register standard CRUD abilities (`viewAny`/`view`/`create`/`update`/`delete`), and override `registerPermissions(PermissionRegistry $registry)` for custom abilities.
- **Sync Command**: `php artisan kinetix:permissions:sync` (with `--prune`) synchronizes the registry to Spatie database tables.
- **Middleware**: `SetPermissionsTeam` maps the active `currentTeam` of the user to Spatie's active team id configuration under `kinetix.permissions.teams`.
- **Super-Admin Bypass**: `Gate::before` callback bypasses checks for users carrying the configured `super_admin_role`.
- **`HasTeams` × `HasRoles` trait collision**: spatie v8 (Laravel 13) ships a `teams()` on `HasRoles`; so does the starter-kit `HasTeams` — using both on the host `User` fatals at boot (`...HasTeams::teams ... collision with ...HasRoles::teams`). The starter-kit `teams()` (real membership; `HasTeams` + the app call it) must win; spatie's is a convenience relation it never calls internally (scoping uses `getPermissionsTeamId()`). Fix with trait conflict resolution — `insteadof` alone is enough: `use HasRoles, HasTeams { HasTeams::teams insteadof HasRoles; }` (optionally `HasRoles::teams as roleTeams;`). Safe because Kinetix bridges team scope via `currentTeam` + `PermissionRegistrar` (`SetPermissionsTeam`), never `$user->teams()`. Documented in `docs/permissions.md` §4.

---

## 12. Kinetix Membership (optional, admin-provisioned onboarding)

Admin-provisioned membership — an **alternative** to the starter-kit's self-serve team invitations. An admin adds an email + role; the person activates by setting a password via a single-use signed link. No personal team is created and the role is a dynamic Kinetix role drawn from a curated allow-list. **Off by default** (`kinetix.membership.enabled`).

- **Provisions table**: `kinetix_member_provisions` (Kinetix's own directory: `team_id` nullable, `email`, `name`, `role`, `invited_by`, `user_id`, `status` pending|active|revoked, `expires_at`/`activated_at`). NO foreign-key constraints — the host's `teams`/`users` schema is unknown. The host `User` is created only on activation (no orphaned, password-less accounts). Migration publishes under `--tag=kinetix-membership-migrations`.
- **Abilities**: registers a `members` feature (`viewAny`/`provision`/`update`/`revoke`) with `PermissionRegistry` when enabled, so it shows in the matrix + sync. Management endpoints (`MembershipController`) are team-aware and gated via `Gate::authorize('members.*')` exactly like `roles.manage`; ids resolved by route-param NAME (the `{current_team}` prefix would shift positional args).
- **Allow-list = the security boundary**: `membership.assignable_roles` (default `['editor','viewer']`) is the ONLY set a provisioner may assign — `assertAssignable()` rejects anything else with 422, enforced at provision AND re-checked at activation (config could change between the two). This is what guarantees "added members never become admin". The role dropdown is driven by the same list (returned from the `index` endpoint).
- **Activation**: `URL::temporarySignedRoute('kinetix.membership.activate.show', …)` — no bespoke token column; validity = the signature plus the provision still being `pending`/unexpired (else 410). GET + POST share the same path so one signed URL covers both (the form posts back to itself). On success: create `user_model`, run optional `attach_member` callback, `assignRole` inside `withTeam()` (pins spatie's team id to the provision's team, then restores), mark `active`, `Auth::login`.
- **Host decoupling**: Kinetix NEVER touches the host's team pivot. `attach_member`/`detach_member` config callables (`fn ($user, MemberProvision $provision) => void`) let the host (de)attach its own membership row; the `index`/`revoke`/role-change all operate on the provisions table + spatie roles only.
- **Notification**: `MemberActivationNotification` (ShouldQueue, mail) sends the signed link; subject/body via `__('kinetix.member_activation_*')`.
- **Vue (published, token-only, vue-i18n)**: `KinetixMemberList` (drop-in directory: embeds the form + lists members with status badge, role `<select>`, resend/revoke), `KinetixMemberProvisioner` (presentational form — props `assignableRoles`, emits `submit`), `KinetixMemberActivation` (public set-password page — props `email`/`action`, Inertia `useForm` posts to the signed `action`). Composable `useKinetixMembers` (`load`/`provision`/`resend`/`updateRole`/`revoke` + reactive `provisions`/`assignableRoles`). Type `KinetixMemberProvision`; keys `member_*`/`activation_*` in all four langs. Render the activation page via `config('kinetix.membership.activation_view')` (default `Kinetix/MemberActivation`).
- Full guide: `docs/membership.md`.

---

## 13. Kinetix Settings (optional, database-backed config)

A class-based settings panel built on the Forms engine. **Off by default** (`kinetix.settings.enabled`). The first roadmap module (v0.5.0) — foundational and zero-dependency.

- **Store**: `kinetix_settings` table (`team_id` nullable = global, `key`, `value` TEXT JSON, `encrypted` bool, `unique(team_id, key)`). Migration publishes under `--tag=kinetix-settings-migrations`. Read/write only through `SettingsManager` / the `KinetixSettings` facade — not the `Setting` model directly.
- **Manager** (`SettingsManager`, singleton): `get/set/forget/all`, scoped by `currentTeam` when `settings.teams` on (null = global), values JSON-encoded (type preserved), `encrypted: true` → `Crypt::encryptString`. A whole scope is loaded once and cached (`Cache::rememberForever`, key `kinetix.settings:{scope}`) + an in-request memo; **cache + memo are flushed on every write** — keep that invariant.
- **`SettingsPage`** (abstract): subclass + `schema()` returns Form components; each field persists under `{group}.{field}` (group = kebab class basename minus `SettingsPage`). `save($input)` runs the field set through the **Form** (`validate()` then `getState()`) and persists; `encrypted()` lists field names to encrypt. `toArray()` = `{key,title,icon,form}` (form filled with current values). Pages registered via `KinetixSettings::pages([...])` or `config('kinetix.settings.pages')`, resolved by `SettingsRegistry::find(key)`.
- **Controller/routes** (`SettingsController`, gated `settings.manage`): `index`/`show` render `config('kinetix.settings.view')` (default `Kinetix/Settings`); `update` validates+saves and returns JSON. Team-aware prefix; ids resolved by route-param NAME. The `settings` permission feature auto-registers when the module is enabled.
- **Vue (published)**: `KinetixSettingsForm` (reuses `<KinetixForm>` + own v4 submit button, posts via the composable), `useKinetixSettings` (`save(pageKey, values)` + `saving`), type `KinetixSettingsPageData`, i18n `settings_saved`. Generator: `kinetix:make-settings-page`.
- Full guide: `docs/settings.md`. Strategy/sequence for the SaaS modules: `ROADMAP.md`.

---

## 14. Kinetix Activity (optional, audit trail + event spine)

Native, team-scoped audit log and the event spine later modules consume. **Off by default** (`kinetix.activity.enabled`). Roadmap v0.6.0.

- **Store**: `kinetix_activity` table (`team_id` nullable = global, `log_name`, `event`, `description`, polymorphic `subject_*` + `causer_*`, `properties` JSON `{old, attributes}` diff). Indexed on `(subject_type, subject_id)`, `(causer_type, causer_id)`, `team_id`, `created_at`. Migration tag `--tag=kinetix-activity-migrations`.
- **Recorder** (`ActivityLogger`, singleton; facade `KinetixActivity`): `log($event, ?$subject, $properties, ?$causer, ?$description, $logName)` (causer defaults to `auth()->user()`), `query($filters)` (paginated, team-scoped, eager-loads `causer` → no N+1), `prune($days)`. `KinetixActivity::for($subject)` = paginated entries for one record. Every `log()` dispatches **`ActivityLogged`** (the spine — Impersonation/Webhooks hook here).
- **Model trait** `LogsKinetixActivity`: boots created/updated/deleted observers; updates capture `getChanges()` (new) vs `getOriginal()` (old) minus `kinetixActivityIgnored()` (defaults: timestamps/password/remember_token). No-ops when disabled. (It's a host-applied trait → `trait.unused` is ignored in `phpstan.neon`, like `HasPlan`.)
- **Controller/route**: `GET {prefix}/activity` (team-aware, gated `activity.view`, auto-registers the `activity` permission feature). Returns `{data: ActivityData[], pagination}`. Command `kinetix:activity:prune {--days=}` (schedule it; retention via `kinetix.activity.retention_days`).
- **Vue (published)**: `KinetixActivityLog` (self-loading timeline; props `subject-type`/`subject-id`/`event`; "load more" pagination; **localized descriptions** composed from i18n `activity_event_*` + `activity_by`/`activity_system` so "Created by X" / "Actualizado por X" translate; skeleton on first load), `useKinetixActivity` (`load(params)`), types `KinetixActivityEntry` / `KinetixActivityResponse`. Per-feature use = drop it on a Resource's View/Show page scoped to the record; global use = no subject.
- **Causer name**: resolved in `ActivityData::fromModel` (accepts a generic `Model`, normalizes `properties` whether it's an array or a Collection) via `causer->getAttribute('name')` — tolerates a model with no `name` and deleted causers. This is what makes the DTO driver-agnostic.
- **Driver** (`ActivityLogger::usesSpatie()`): `config('kinetix.activity.driver')` — `auto` (default) uses `spatie/laravel-activitylog` when `ActivitylogServiceProvider` exists, else native; `spatie`/`native` force it. `logViaSpatie` uses the `activity()` builder and carries the team in `properties.team_id` (no spatie schema change); `queryViaSpatie` reads spatie's `activity_model` filtering on `properties->team_id`; `prune` delegates to `activitylog:clean` under spatie. The `LogsKinetixActivity` trait computes the `{old, attributes}` diff before the driver, so the diff is identical either way. `ActivityLogged` carries a base `Model` (native or spatie). spatie is a dev dep + `suggest`; tests pin the driver (`ActivityTest` → native, `ActivitySpatieDriverTest` → spatie). **Spatie is preferred when present — don't reimplement audit logic; the native store is the zero-dependency fallback.**
- Full guide: `docs/activity.md`.

---

## 15. Kinetix Impersonation (optional, "log in as user")

Admin impersonation, audited via the Activity spine. **Off by default** (`kinetix.impersonation.enabled`). Roadmap v0.7.0. No migration — state lives in the session.

- **Manager** (`ImpersonationManager`, singleton; facade `KinetixImpersonation`): `start($target)` stores `auth()->id()` in `session('kinetix_impersonator_id')` then `auth()->login($target)`; `stop()` retrieves the original via `auth()->getProvider()->retrieveById()` and logs back in; `isImpersonating()`/`impersonatorId()`. Target resolved by id through the auth provider → no User model reference.
- **Escalation guard** `canImpersonate($impersonator, $target)`: false for self; honors a `can_impersonate` closure; otherwise the built-in rule blocks impersonating a `super_admin_role` holder unless the impersonator is one too. The `users.impersonate` ability (auto-registered when enabled) governs WHO may impersonate — the controller authorizes it; the guard only prevents the catastrophic case. Document that finer rules go in the closure.
- **Controller/routes**: `POST {prefix}/impersonate/{user}` (start, gated `users.impersonate`), `DELETE {prefix}/impersonate` (leave — open, the impersonated user must be able to exit; `leave` route registered BEFORE `{user}` so it isn't captured). Team-aware prefix.
- **Sensitive-route protection**: `DenyWhileImpersonating` middleware, aliased `kinetix.impersonation.protect` (aliased always, even when the feature is off). 403s while impersonating — host applies it to password/email/2FA/billing/account-deletion routes.
- **Audit**: `start`/`stop` call `KinetixActivity::log('impersonate.start|stop', ...)` (causer = the admin), guarded by `config('kinetix.activity.enabled')` so it's safe when Activity is off.
- **Inertia prop** `kinetix_impersonation` (`{active, user:{id,name}}`) shared in `shareInertiaData()` only when enabled + impersonating.
- **Vue (published)**: `KinetixImpersonationBanner` (renders only when active; "Return to your account" → `router.delete` the leave route), `useKinetixImpersonation` (`active`/`impersonatedName`/`leave`), type `KinetixImpersonationState`, i18n `impersonate`/`impersonating`/`impersonation_leave`. `ImpersonateAction` (prebuilt Action, icon `user`, `authorize('users.impersonate')`, `inertiaVisit(post)`).
- Full guide: `docs/impersonation.md`.

---

## 16. Kinetix Feature Flags (optional, pennant bridge / native)

Gradual rollout + plan-gating. **Off by default** (`kinetix.features.enabled`). Roadmap v0.8.0. No Kinetix migration.

- **Manager** (`FeatureManager`, singleton; facade `KinetixFeatures`): `define($name, Closure|bool)`, `active($name, $scope=null)`, `inactive()`, `all($scope=null): array<string,bool>`. `usesPennant()`: `config('kinetix.features.driver')` — `auto` (default) = `class_exists(Laravel\Pennant\Feature)`, else native; `pennant`/`native` force it. Pennant path forwards to `Feature::define` / `Feature::for($scope)->active()/all()`; native evaluates the stored closures/bools each request. `defaultScope()` = the team (when `features.teams`) else the auth user. `all()` casts pennant's (possibly rich) values to bool.
- **Definitions are code, not config** — host calls `KinetixFeatures::define()` in a provider's boot. A resolver is `fn ($scope) => bool` and can defer to anything, incl. Billing `$user->canUseFeature(...)` (plan-gating is just a flag whose resolver asks Billing — no separate mechanism).
- **Middleware** `EnsureFeature` aliased `kinetix.feature` (always aliased): `kinetix.feature:flag` **404s** when inactive (route "doesn't exist" for users without it).
- **Inertia prop** `kinetix_features` (resolved `name=>bool` map) shared when enabled.
- **Vue (published)**: `useKinetixFeature` (`active`/`inactive`/`flags`, reactive), `KinetixFeature` gate component (`flag` prop + `#denied` slot, mirrors `<KinetixCan>`). No new i18n. `kinetix_features?: Record<string,boolean>` on `KinetixSharedProps`.
- `laravel/pennant` is a dev dep + `suggest`. Tests pin the driver (`FeatureFlagsTest` → native, `FeatureFlagsPennantTest` → pennant with `pennant.default=array`, no migration).
- Full guide: `docs/feature-flags.md`.

---

## 17. Kinetix Spotlight (optional, Cmd+K command palette)

Global search over models, navigation and actions, authorization-aware. **Off by default** (`kinetix.spotlight.enabled`). Roadmap v0.9.0. No migration.

- **Sources** (`SpotlightSource` interface: `authorizedFor(?$user)`, `search($q): SpotlightItemData[]`): `SpotlightResource` (model search — `titleAttribute`/`subtitle`/`searchColumns`/`url`/`query`/`icon`/`group`/`authorize`/`limit`) and `SpotlightLink` (static nav `url` or client `event`, `keywords`, `authorize`). Registered via `KinetixSpotlight::register([...])` into the `SpotlightRegistry` singleton.
- **Driver**: `SpotlightResource::usesScout()` = `config('kinetix.spotlight.driver')` (`auto`/`database`) + `trait_exists(Laravel\Scout\Searchable)` + `in_array(Searchable, class_uses_recursive($model))`. **Use `trait_exists`, NOT `class_exists`** (Searchable is a trait → class_exists returns false). Scout path: `$model::search($q)->take()->get()` (phpstan ignore for `Model::search()` by path, like cashier). LIKE path: `orWhere(col,'like',"%q%")` over `searchColumns`.
- **Authorization (two layers, server-side)**: source-level `->authorize($ability)` (Gate::allows) hides the whole source; per-record `Gate::allows('view', $record)` when `Gate::getPolicyFor($model)` exists. Empty query → resources return `[]` (no DB dump); only links show.
- **Controller/route**: `GET {prefix}/spotlight?q=` (team-aware) → `{groups:[{label, items: SpotlightItemData}]}`. `SpotlightItemData` = `{type, group, title, subtitle, url, event, icon, id}`.
- **Vue (published)**: `KinetixSpotlight` (owns Cmd/Ctrl+K; Reka `DialogRoot` + `ComboboxRoot`(`:ignore-filter` since server-filtered) + `ComboboxInput`/`Content`/`Group`/`Item`/`Empty`; debounced 200ms with cleanup; select → `router.visit(url)` or `window.dispatchEvent(new CustomEvent(event))`; `VisuallyHidden` DialogTitle for a11y), `useKinetixSpotlight().search(q)`, types `KinetixSpotlightItem`/`KinetixSpotlightGroup`, i18n `spotlight_placeholder`/`spotlight_empty`.
- `laravel/scout` is a dev dep + `suggest`. Tests: `SpotlightTest` (database driver + per-record policy + source ability + endpoint), `SpotlightScoutTest` (scout `collection` driver).
- Full guide: `docs/spotlight.md`.

---

## 18. Kinetix Webhooks (optional, outbound event delivery)

Customers subscribe endpoints to platform events; signed/queued/retried/logged delivery with SSRF protection. **Off by default** (`kinetix.webhooks.enabled`). Roadmap v0.10.0. Two migrations (`kinetix-webhooks-migrations`): `kinetix_webhook_endpoints` (team_id, name, url, secret, events json, active) + `kinetix_webhook_logs` (endpoint_id, event, payload, status_code, success, attempt, response).

- **Events**: `WebhookEventRegistry` (singleton) — `KinetixWebhooks::events(['order.created'=>'…'])` declares the subscribable catalog; **only registered events fire**. `WebhookDispatcher::fire($event, $payload)` (facade `KinetixWebhooks::fire`) fans out to active endpoints (team-scoped) subscribed to the event, queueing one `DispatchWebhookJob` each. This is the explicit fire API (host calls it from domain code) — the event spine companion.
- **Delivery** (`DispatchWebhookJob`, ShouldQueue): re-checks SSRF, signs body `hash_hmac('sha256', $body, secret)` → `X-Kinetix-Signature` (+ `X-Kinetix-Event`), POSTs via `Http::timeout()`, logs the attempt; non-2xx/transport error throws → queue retries (`tries`/`backoff`). Body = `{event, data}`.
- **SSRF guard** (`WebhookUrlGuard::isAllowed`): scheme http(s) only; resolves host (IP literal or `gethostbynamel`) and rejects any private/reserved IP via `filter_var(FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE)`. `allow_private` config bypasses (dev only). **Validate at save AND before each delivery.**
- **Controller** (`webhooks.manage`, team-scoped): index (endpoints + event catalog), store (returns secret ONCE — `whsec_`+random; never serialized by `WebhookEndpointData`), update, destroy, rotate, test (queues `webhook.test`), logs (paginated), redeliver. URL validated via `WebhookUrlGuard` in `validatePayload`; events validated against the registry.
- **Vue (published)**: `KinetixWebhookManager` (CRUD + events via `KinetixCheckbox`, inputs via `inputClass`, rotate/test/logs, secret via toast once), `useKinetixWebhooks`, types `KinetixWebhookEndpoint`/`KinetixWebhookLog`, DTOs `WebhookEndpointData`/`WebhookLogData`, i18n `webhook_*`. Command `kinetix:webhooks:prune`.
- **Scope note**: native delivery is the default (powers the dashboard logs). A `spatie/laravel-webhook-server` bridge is a documented opt-in increment — don't ship it untested.
- Full guide: `docs/webhooks.md`.

---

## Generators (Artisan)

`kinetix:make-resource` (full CRUD: `--generate`/`--simple`/`--soft-deletes`/`--team`), `kinetix:make-action`, `make-table`, `make-form`, `make-infolist`, `make-importer`, `make-exporter`, `make-relation-manager`, `make-notification`, `kinetix:make-billing` (`--seeder`). All write to `app/Kinetix/{Type}/` (billing → `resources/js/pages/Billing/`) and accept `--force`. Built on a shared `GeneratorCommand` base.

## Testing & Static Analysis

- **PHPUnit + orchestra/testbench** (`vendor/bin/phpunit`, in-memory sqlite) under `tests/` (`Happones\Kinetix\Tests\`). **Larastan/PHPStan level 5** (`vendor/bin/phpstan analyse`, config `phpstan.neon`). **Vitest + @vue/test-utils + happy-dom** for Vue (`npm run test:unit`, specs in `resources/js/components/__tests__/`; i18n components need an i18n plugin via `global.plugins`). Every change must keep all three green.

