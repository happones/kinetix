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
- **Pint Formatter (REQUIRED — run the FULL repo, every time)**: Before finishing any change, run `vendor/bin/pint --format agent` over the **whole repo** (not `--dirty`) and confirm `vendor/bin/pint --test` is clean. `--dirty` only touches files changed since the last commit, so it **misses files made non-compliant by an earlier edit** — most commonly the `binary_operator_spaces` `=>` re-alignment in `resources/lang/*/kinetix.php` when a translation key of a different length is added. CI runs `pint --test`; a stale alignment there fails the build even when your diff looks fine.
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
- **Interactive primitives via Reka UI**: Build accessible widgets on Reka UI (the headless lib shadcn-vue wraps), never by importing the consumer's copied `@/components/ui/*` files (per-app, unguaranteed, break builds). In use: `KinetixCheckbox` (CheckboxRoot), Forms toggle + the table `toggle-input` cell (SwitchRoot / manual button), `KinetixSelect` (SelectRoot), `KinetixRadioGroup` (RadioGroupRoot), `KinetixRangeCalendar` (RangeCalendarRoot), `KinetixLabel` (Label), `KinetixCombobox` (Combobox, searchable select), `KinetixSpotlight` (Dialog+Combobox). `reka-ui` + `@internationalized/date` are optional peer deps.
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
- Chart types (v0.60.0): `line` | `area` (stacked `VisArea` array-y + per-series `VisLine`) | `bar` (`VisGroupedBar`, or `VisStackedBar` when `->stacked()`) | `horizontalBar` (**div-based**, not unovis — label + bar width% + value, from dataset[0]) | `pie`/`doughnut` (`VisDonut`). ChartWidget fluent: `->stacked()`, `->legend()` (swatch legend below — dataset labels for XY, category labels for donut/hbar), `->centerLabel($value,$caption)` (donut center overlay). Serialized keys `stacked`/`legend`/`centerValue`/`centerLabel`. Tests: `ChartWidgetVariantsTest`.

### Widget variants (v0.59.0)
- **Stat icons**: `Stat::make()->icon('dollar-sign')->iconColor('info'|'success'|'warning'|'danger'|'gray')` → serialized `icon`/`iconColor`. `KinetixStatsOverviewWidget` renders a soft-colored icon badge (size-11 rounded-xl, `statusSoftClass`, `resolveIcon`) **in place of** the sparkline when an icon is set. `KinetixStat` TS gains `icon`/`iconColor`.
- **`ListWidget`** (`src/Widgets/ListWidget.php`, type `list`) + **`ListItem`** (`src/Widgets/Lists/ListItem.php`): `ListWidget::make()->items([ListItem::make($title)->subtitle()->icon($name,$color)->value()->badge($text,$color)->progress(0-100 clamped)->url()])->icon()->action($label,$url)->emptyState()`. `KinetixListWidget.vue` (Card; rows = icon badge + title/subtitle + trailing value/badge + progress bar; row is `<a>` when `url`; footer link button). Registered in `KinetixWidgetsGrid` as `type === 'list'`; `KinetixWidget.type` includes `list`; TS `KinetixListItem`.
- **`resolveIcon` (`useKinetixIcons`)** extended with dashboard icons: dollar-sign, wallet, shopping-cart/bag, package/cube/box, users, clock, activity, alert-circle/triangle, trending-up. Used by both the stat badge and list widget.
- **Stat badge + link (v0.61.0)**: `Stat::badge($text,$color)` (small trend chip in the card header row, `statusBadgeClass`, reuses `descriptionIcon`) + `Stat::url($label,$href)` (footer link with `ArrowUpRight`). Serialized `badge`/`badgeColor`/`linkLabel`/`linkUrl`; TS `KinetixStat` extended.
- **Widget header actions (v0.61.0)**: base `Widget::headerAction($label,$url,$icon?)` (chainable, → `headerActions[]` in toArray). Shared **`WidgetHeaderActions.vue`** (`resources/js/components/widgets/`) renders link buttons; included in Chart/Table/List widget headers (header restructured to flex justify-between, shown also when only actions exist). TS `KinetixWidgetAction`; `KinetixWidget.headerActions`. Tests: `WidgetVariantsTest` (badge/link/headerActions), `WidgetHeaderActions.spec.ts`. Custom slots for arbitrary content = `CustomWidget` + per-id named slot in `KinetixWidgetsGrid` (already supported).
- **Hero widget + chart metrics (v0.64.0)**: `HeroWidget` (type `hero`) — `subtitle/value/delta($text,$color)/action($label,$url)/gradient()`; `KinetixHeroWidget.vue` (greeting + big value + delta `statusTextClass` + button; optional `bg-gradient-to-br from-primary/10`). Registered `type==='hero'` in grid; `KinetixWidget.type` +hero. `ChartWidget::metric($label,$value,$badge?,$badgeColor?)` (chainable) → `metrics[]`; rendered in the chart header right side (uppercase label + bold value + optional badge) before `WidgetHeaderActions`. TS `KinetixChartMetric`. Tests: `HeroWidgetTest` (hero + chart metrics), `KinetixHeroWidget.spec.ts`.
- **Rating widget (v0.63.0)**: `RatingWidget` (type `rating`) — `average()/total()/max()/breakdown([level=>count])`; `getData` emits breakdown high→low with `pct` (count/maxCount). `KinetixRatingWidget.vue` (big average + clipped-overlay half-stars; per-level bars colored green≥4/amber=3/orange=2/red=1; counts `toLocaleString`; supports header actions). Registered `type==='rating'` in grid; TS `KinetixRatingLevel`; `KinetixWidget.type` +rating. i18n `rating_out_of`/`rating_reviews`. Tests: `RatingWidgetTest`, `KinetixRatingWidget.spec.ts`.
- **Period filter (v0.62.0)**: `Support\Period` parser — `KEYS` const, `range($key,$from?,$to?)` → `[CarbonImmutable|null, CarbonImmutable|null]` (today/yesterday/7d/30d/90d/month/year/all + custom; `all`/unknown → `[null,null]`), `fromRequest($request,$default)` (reads `?period=` + `?from=&to=`), `scope(Builder,$column,$key)` applies `>=`/`<=` (no-op when unbounded). `useKinetixPeriod(initial?, {navigate, only})` composable → `{period (seeded from ?period), range ({start,end} ISO via `resolvePeriodRange`), setPeriod}` (navigate → `router.get` with merged query). `KinetixPeriodFilter.vue` (variant `segmented` buttons | `select` reka DropdownMenu; `periods` prop; v-model key; emits update:modelValue+change; no-op on active). i18n `period_*`. **Same key set across PHP+TS+component**. Tests: `PeriodTest` (range keys with `CarbonImmutable::setTestNow`, fromRequest, scope bindings count), `KinetixPeriodFilter.spec.ts` (+ `resolvePeriodRange`). Docs `period-filter.md`.
- Tests: `WidgetVariantsTest` (Stat icon, StatsOverview carries icon, ListItem all fields + progress clamp, ListWidget items+action), `KinetixListWidget.spec.ts` (title/items/action, progress width + url link, empty state). Gallery: `statsWidget` shows POS-style icon cards, `listWidget` specimen.

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

### Summaries (footer + export)
- `Column::summarize(Summarizer|Summarizer[])` adds aggregate(s) rendered in a `<tfoot>` row. Summarizers live in `Tables\Columns\Summarizers`: `Sum`, `Average`, `Count` (rows; `->query(fn($q)=>…)` scopes), `Range` (min–max; `->excludeNull(false)`, `->minimalTextualDifference()`, `->minimalDateTimeDifference()`, `->limit(n)`), plus the base `Summarizer::make()->using(fn($q)=>…)` for custom values. Shared methods: `label()`, `query()` (scope clone), `prefix()/suffix()`, `numeric(decimalPlaces, locale)`, `money(currency, divideBy, locale)` (intl `NumberFormatter`, fallback `number_format`; locale ← arg / `config('kinetix.tables.number_locale')` / app locale), `hidden()/visible()` (closure gets `$query`).
- `Table::toData()` computes summaries over the **full filtered dataset** (clone of the resolved query, pre-pagination) via `computeSummaries()` → `TableData.summaries` (`array<colName, SummaryData[]>`) + `hasSummaries`; `ColumnData.hasSummary`. Frontend `KinetixTable.vue` renders a `<tfoot>`; leading summary-less column shows `summary_total` ("Total") i18n label.
- **Export parity**: `ExportColumn::summarize(...)` mirrors it; `Exporter::summaryRow($query)` builds an aligned totals row (multiple summarizers joined ` / `; label prefix `Label: value`) appended by `ExportProcessor` after the data chunks. `Exporter::hasSummary()` reflects whether a row will be written; override `protected bool $withSummary = false` to suppress. Summary computed over `resolveExportQuery()` (so bulk/selected-rows exports total exactly those rows).

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
- **Layout components**: `Grid` (N-col), `Section` (card + heading/description/columns), `Fieldset` (bordered `<legend>` group + columns), `Tabs`→`Tab` (Reka tab strip; `Tab::make()->icon()->schema()`; serializes `type:'tabs'` with `schema` = `type:'tab'` children carrying `heading`/`icon`/`schema`/`columns`; rendered by `KinetixFormTabs` with local active state), `Split` (responsive flex row — `flex-col md:flex-row [&>*]:flex-1`), `Placeholder` (read-only `label`+`content`, NOT a field — excluded from validation/dehydration), `Wizard`→`Step` (see §22). All non-field layouts with a `schema` property are auto-recursed by `Form::extractFields()` (reflection), so nested fields anywhere are validated/hydrated. `FormFieldData` carries `icon` (tabs/wizard-step), `content` (placeholder), `variant`/`slug` (wizard), `isRequired` (fields, for wizard step gating).
- **Base Fields**: All actual input controls extend `Field`, inheriting validation rules (`rules()`, `required()`, `maxLength()`), defaultValue configurations, and hydration/dehydration callbacks.
- **Available Fields**: `TextInput`, `Textarea`, `Select`, `Checkbox`, `Toggle`, `DatePicker`, `DateTimePicker`, `TimePicker`, `Hidden`, `Radio`, `CheckboxList`, `ColorPicker`, `TagsInput`, `KeyValue`, `Repeater`, `FileUpload`.
- **`TimePicker`** (type `time-picker`): time-only field storing `H:i`. Rendered by `KinetixTimePicker` as an **input-style trigger → popover** with scrollable hour/minute(/AM-PM) columns (`->native()` → `<input type=time>`), `minuteStep()`. **Defaults to 12-hour + AM/PM** (`hour12=true`); `->twentyFourHour()` (or `->twelveHour(false)`) opts to 24h. Scrolls the selected hour/minute into view on open (`centerInScrollParent`, scrolls only the ScrollArea, never the page). DateTimePicker does the same on popover open and keeps its 24h default.
- **`MonthPicker`/`YearPicker`/`WeekPicker`** (types `month-picker`/`year-picker`/`week-picker`): coarse date fields. Shadcn popover by default (`->native()` → native `<input type=month/number/week>`), `->minValue()/->maxValue()` bounds (generic on `Field` now → also exposed on DatePicker/DateTimePicker; serialized as `FormFieldData.minValue/maxValue`). Stores `Y-m` / `Y` / `o-\WW`. Vue: `KinetixMonthPicker` (month grid + year nav), `KinetixYearPicker` (paginated year grid), `KinetixWeekPicker` → **`KinetixWeekCalendar`** (clicking a day highlights its **whole week** row, range-style). `WeekPicker`/`WeekFilter` take **`->startWeek(0-6)`** (region-aware first day; serialized `weekStartsOn` on FormFieldData/FilterData → Reka `weekStartsOn`). The three render through a **single** `KinetixFormSchema` branch each passing `:native="!comp.useCalendar"`. Matching **filters** `MonthFilter`/`YearFilter`/`WeekFilter` (types `month`/`year`/`week`): `whereYear`(+`whereMonth`) / `whereYear` / `whereDate` range; same `useCalendar`/`minValue`/`maxValue`/`locale` extra-data; rendered by the same picker components in `KinetixTable`'s filter panel.
- **`Slider`/`Rating`/`PinInput`** (v0.34.0): (a) `Slider` (type `slider`): reka `SliderRoot/Track/Range/Thumb`, single value, reuses `FormFieldData.numberConfig` (`min/max/step`); `KinetixSlider` shows the value beside the track, emits a number. (b) `Rating` (type `rating`): `->max(int)/->allowHalf()` → `FormFieldData.ratingConfig` `{max,allowHalf}`; `KinetixRating` is **custom** (lucide `Star`/`StarHalf`, hover + half-on-left-click, click current value to clear) — not reka Rating (whose half-clipping is fiddly). (c) `PinInput` (type `pin-input`): `->length()/->mask()/->otp()/->numeric()` → `FormFieldData.pinConfig` `{length,mask,otp,type}`; `KinetixPinInput` uses reka `PinInputRoot/Input` (stores joined string; reka adds a 5th binding input w/o `inputmode` — segments carry `inputmode`). No new i18n.
- **`PhoneInput`** (type `phone-input`, v0.36.0): international phone field. `->defaultCountry($code)/->countries([$codes])`. `toData()` builds `FormFieldData.phoneConfig` `{defaultCountry, countries:[{code,name,dial}]}` from `Support\Countries::all()` + new `Support\DialCodes::all()` (ISO→ITU E.164 calling code map; `DialCodes::for($code)`), sorted by name. `KinetixPhoneInput` = searchable `KinetixCombobox` (flag emoji via regional-indicator code points + name + dial) + national `<tel>` input with the `+dial` prefix shown; emits the joined E.164 string `+<dial><digits>`; on init derives the country by longest dial-prefix match of the value, else `defaultCountry`. No new i18n (combobox needs the i18n plugin in tests).
- **`SlugInput`/`SignaturePad`** (v0.35.0): (a) `SlugInput` (type `slug-input`): `->from($field)/->separator()` → `FormFieldData.slugConfig` `{from,separator}`. `KinetixFormSchema` passes `:source="values[comp.slugConfig.from]"`; `KinetixSlugInput` slugifies the source live (NFKD accent-strip + lowercase + non-alnum→sep) until the user edits it (`manual` flag), then stops syncing. (b) `SignaturePad` (type `signature-pad`): `->penColor()/->backgroundColor()/->height()` (floor 80) → `FormFieldData.signatureConfig`. `KinetixSignaturePad` is a custom canvas (Pointer Events, devicePixelRatio scaling, re-draws on resize, seeds from an existing data URL); emits a **PNG data URL** on stroke end / `null` on Clear. i18n `signature_clear`.
- **`NumberField`** (type `number-field`, v0.33.0): numeric input with increment/decrement steppers (Reka `NumberFieldRoot/Input/Increment/Decrement`). `->min()/->max()/->step()/->decimals(min,max?)/->percent()/->currency($code)/->numberLocale()`. `toData()` sets `FormFieldData.numberConfig` `{min,max,step,format(decimal|percent|currency),currency,decimals:{min,max}|null,locale}`. Vue `KinetixNumberField` builds `Intl.NumberFormat` `formatOptions` from config; emits a number or null; `compact` prop for table cells. Editable table twin **`NumberInputColumn`** (type `number-input`, `isEditable()`, same builder methods) → `ColumnData.numberConfig`; rendered in `Table/KinetixTableCell` (`number-input` branch) via `KinetixNumberField compact`, saving through the cell-update endpoint. No new i18n.
- **`RichEditor`** (type `rich-editor`, v0.30.0): rich text / WYSIWYG with 3 swappable drivers. Default from `config('kinetix.forms.rich_editor')` (block `forms.rich_editor`, default `basic`); per-field `->editor('basic'|'tiptap'|'markdown')` + shortcuts `->basic()/->tiptap()/->markdown()`. `toData()` sets `FormFieldData.editor`. Vue `KinetixRichEditor` dispatches to: `KinetixRichEditorBasic` (zero-dep contenteditable + `execCommand` toolbar → HTML), `KinetixRichEditorMarkdown` (zero-dep textarea + write/preview tabs, tiny **HTML-escaping** md renderer → Markdown), `KinetixRichEditorTiptap` (imperative `new Editor()` from `@tiptap/core`+`@tiptap/starter-kit`, both **MIT**, **lazy** via `import(/* @vite-ignore */)` so it's an OPTIONAL dep — on failure shows `editor_tiptap_missing` notice). HTML is NOT sanitized server-side (documented). i18n `editor_write/editor_preview/editor_tiptap_missing`. Tiptap is a **devDependency** here (for gallery/type-check); consumers `npm i @tiptap/core @tiptap/starter-kit` only for that driver.
- **`DateRangePicker`** (type `date-range-picker`): range field storing `{from,to}`. Shadcn range calendar in a popover (`KinetixDateRangePicker` wraps `KinetixRangeCalendar`) by default, or two native date inputs via `->native()`. `->numberOfMonths()/->weekdayFormat()/->fixedWeeks()/->locale()` + `minValue/maxValue`. New `Field::rangeConfig()` (defaults `numberOfMonths`/`weekdayFormat`/`fixedWeeks`) → `FormFieldData`; DateRangePicker overrides it. The **filter** twin `DateRangeFilter` already existed (whereDate `>=from`/`<=to`).
- **`AddressPicker`** (type `address-picker`): structured field storing `{line1,line2,city,state,postalCode,country}`. `toData()` overrides set `FormFieldData.addressFields` (the sub-field list/order, intersected with `AddressPicker::FIELDS`) and `options` (country code=>label). `->fields([...])` limits/orders sub-fields; `->countries([...])` overrides options (default `Support\Countries::all()` — built-in ISO 3166-1 alpha-2 list). Vue `KinetixAddressPicker` renders a text input per sub-field + a searchable `KinetixCombobox` for `country`; line1/line2 span the 2-col grid. Filter twin **`AddressFilter`** (type `address`): single text input → OR `LIKE` across `->columns([...])` (default = filter name); rendered as a plain text input in `KinetixTable`'s filter panel. i18n keys `address_line1/line2/city/state/postal/country` + `address_search`.
- **DatePicker / DateTimePicker default to the shadcn calendar** (`KinetixDatePicker` / `KinetixDateTimePicker`), NOT native inputs. `->native()` opts back to native `<input>`; `->locale()`, `DateTimePicker->minuteStep()`/`->twelveHour()` (AM/PM column). Inputs/textareas/buttons across forms reuse `inputClass`/`textareaClass`/`buttonVariants` from `@/composables/useShadcnVariants` — don't re-hand-roll field/button class strings.
- **Select-derived**: `Radio` and `CheckboxList` extend `Select` to reuse `options()` (incl. Enum reflection). `CheckboxList` stores an **array** (pair with an `array` cast). Both support `inline()`.
- **Array/Object fields**: `TagsInput` (string array) and `KeyValue` (object) keep ephemeral edit state in their own components (`KinetixTagsInput`/`KinetixKeyValue`) to avoid leaks. `Repeater` repeats a `schema()` over an array-of-objects (`minItems`/`maxItems`/`addActionLabel`; rendered by recursing `KinetixFormSchema` per item; extends `Field` so validation treats it as one array field).
- **FileUpload**: stores path(s); uploads via `{prefix}/uploads/store` (+ `uploads/delete`). Storage config (disk/dir/constraints) is signed into an encrypted `uploadToken` and re-validated server-side — the client can't target arbitrary disks. State in `KinetixFileUpload`.
- **Field-specific serialization**: override `toData()`, call `parent::toData()`, then mutate the returned `FormFieldData` (e.g. `$data->inputType`, `$data->isInline`) — mirror `TextInput`.
- **Select Fields**: `options()` accepts array, `Closure`, or a `UnitEnum` class (auto value→label). Rendered by `KinetixSelect` (Reka `SelectRoot`); `Radio` by `KinetixRadioGroup` (Reka), `Toggle` by Reka `SwitchRoot`, `Checkbox` by `KinetixCheckbox` (Reka).
- **Searchable Select → `KinetixCombobox`** (Reka `Combobox`): `Select::searchable()` sets `FormFieldData.isSearchable`; `KinetixFormSchema` then renders `<KinetixCombobox>` instead of `<KinetixSelect>`. Local mode filters `options` client-side (Reka's filter); remote mode = `Select::searchUsing($model,$label,$columns,$value)` which `Crypt::encrypt`s a descriptor into `searchToken` (the column/model can't be user-forged — same guard as table cell-update) and `getFieldOptions` ships only the selected option's label. The combobox debounces (250ms) + lazy-fetches `POST {prefix}/forms/search` (`SearchController`, token-guarded, LIKE over the declared columns, limit 20) and accumulates a `labelMap` so the trigger always shows the selected label. `ignore-filter` is on only in remote mode.

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
- **Export**: extend `Exporter` (`getColumns()` of `ExportColumn`, `$model`/`query()`, `format()` csv|xlsx|**pdf**, `chunkSize()`, `export(?Model $recipient)`). `ExportProcessor` (ShouldQueue) streams via `FileWriter` (format flows straight to the `.$format` file extension + download name), then sends an **"Export ready" notification with a signed Download action**; `kinetix.exports.download` (token-guarded, no team prefix).
- **PDF export** (v0.45.0): `format()` → `'pdf'`. `FileWriter` buffers rows then `writePdf()` builds a landscape-A4 HTML table (first row = header, `htmlspecialchars`'d, zebra striping) and renders via **`dompdf/dompdf`** — an **optional** dep (`suggest` + `require-dev` here): `class_exists(\Dompdf\Dompdf::class)` else throws a RuntimeException with an install hint. No bundled runtime dep for csv/xlsx. Test `PdfExportTest` asserts the output starts with `%PDF-`.
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
- **Inertia prop** `kinetix_features` (resolved `name=>bool` map) shared when enabled — resolves on EVERY response, incl. guests. **Guest safety**: `active()`/`all()` are null-scope-safe — a resolver that throws for a `null` scope (e.g. `fn($user)=>$user->isAdmin()` for a logged-out visitor) resolves **inactive** instead of 500ing; `all()` resolves per-flag so one bad resolver can't break the set. Authenticated-scope errors still surface (`resolve()` is the un-guarded inner; `active()` wraps it only when scope is null).
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
- **Vue (published)**: `KinetixSpotlight` (owns Cmd/Ctrl+K AND a `window` `kinetix:spotlight` event listener so an external trigger can open it; Reka `DialogRoot` + `ComboboxRoot`(`:ignore-filter` since server-filtered) + `ComboboxInput`/`Content`/`Group`/`Item`/`Empty`; debounced 200ms with cleanup; select → `router.visit(url)` or `window.dispatchEvent(new CustomEvent(event))`; `VisuallyHidden` DialogTitle for a11y), `useKinetixSpotlight().search(q)`, types `KinetixSpotlightItem`/`KinetixSpotlightGroup`, i18n `spotlight_placeholder`/`spotlight_empty`.
- **`KinetixSpotlightTrigger`** (published): header launcher (search-box style with `⌘K`/`Ctrl K` hint via `isMac()`, collapses to icon button below `sm`). Dispatches the `window` `kinetix:spotlight` event → opens `<KinetixSpotlight>` (decoupled; the keyboard shortcut still works independently). Pair next to `KinetixNotificationTrigger` in the header.
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
- **Driver** (`WebhookDispatcher::usesWebhookServer()`, config `webhooks.driver` auto|spatie|native; `auto` = `class_exists(Spatie\WebhookServer\WebhookCall)`): native dispatches `DispatchWebhookJob`; spatie creates a `WebhookCall` with `meta(['kinetix_endpoint_id','kinetix_event'])` (SSRF re-checked in the dispatcher since spatie delivers directly). `LogSpatieWebhookCall` listens to spatie's `WebhookCallSucceeded/FailedEvent` and writes a `WebhookLog` (correlated by meta) so the dashboard is driver-agnostic. Driver differences: spatie signs with its `Signature` header (config `webhook-server.signature_header_name`) and uses spatie's `tries`/timeout. Listener registered in `registerWebhooks()` only when `usesWebhookServer()`. spatie/laravel-webhook-server is a dev dep + `suggest`; tests pin the driver (`WebhooksTest` → native, `WebhooksSpatieDriverTest` → spatie).
- Full guide: `docs/webhooks.md`.

---

## 19. Kinetix Keyboard Shortcuts (optional, frontend-only)

App-wide hotkeys. **Frontend-only** — no backend, no config, no migration. Roadmap v0.12.0.

- **Conflict-safe scheme (REQUIRED)**: single keys (`c`/`e`/`d`/`/`) + Gmail-style sequences (`g i`) fire only when NOT typing (input/textarea/select/contenteditable); `mod+…` combos (⌘ on mac, Ctrl else) still fire while typing; `?` opens help. **Never** map browser/OS-reserved `Ctrl+`-combos (copy/new/save/print). `preventDefault` only on a match.
- **Core** (`useKinetixHotkeys.ts`, module-level singleton + ONE global `keydown` listener via `ensureHotkeysListening`): pure, exported, unit-tested helpers `eventMatchesStep` (modifiers: `mod`=meta||ctrl, `alt`; `shift` required only when declared, not forbidden — for symbol keys like `?`), `sequenceMatches` (tail of a time-windowed plain-key buffer, 1s), `normalizeKey`, `isTypingTarget`, `isMac`. Grammar: space-separated steps = sequence; `+`-join modifiers+key within a step; sequences are plain keys only. `addHotkey`/`removeHotkey`/`setHotkeyOverrides`/`listHotkeys` are module fns; `useKinetixHotkeys().register()` wraps `addHotkey` + `onScopeDispose`.
- **Directive** (`plugins/kinetixHotkeys.ts`, `app.use(KinetixHotkeys)` → `v-kinetix-hotkey`): string value → `el.click()` on match; object `{keys,handler}` → handler; `:arg` = help label. Manages add/remove in mounted/unmounted (not the composable's scope-dispose). This is the "bind a key, fire an event in any component" surface.
- **Action integration**: `Action::shortcut('c')` (PHP) serializes to `ActionData.shortcut`; `KinetixPageHeader` registers a hotkey per header action with a shortcut (→ `requestAction`, so confirmation still applies). Group actions are skipped. Per-row table actions intentionally have no shortcut (ambiguous).
- **Help overlay** (`KinetixShortcuts.vue`, Reka `Dialog`): registers `?`, lists labelled shortcuts as `<kbd>` chips. Mount once.
- **Customization**: `setHotkeyOverrides({id: keys})` (effective keys = override ?? default), persisted via the Settings module (`shortcuts.bindings`).
- **Native matcher, no hard dep** (mirrors Spotlight's Cmd+K); `@vueuse/core` `useMagicKeys` documented as a drop-in alternative. i18n `shortcuts_title`. Tested in `tests/js/composables/useKinetixHotkeys.spec.ts`.
- Full guide: `docs/keyboard-shortcuts.md`.

---

## 20. Kinetix Developer Tokens (optional, requires laravel/sanctum)

Self-service personal access tokens. Roadmap v0.14.0. **Requires `laravel/sanctum`** + the authenticatable model uses `Laravel\Sanctum\HasApiTokens` (else endpoints abort 500). Config block `tokens` (`enabled`, `scopes` = key→label).

- **Scope registry** (`TokenScopeRegistry`, singleton seeded from `config('kinetix.tokens.scopes')`; facade `KinetixTokens::scopes([...])` merges more at boot): the catalog of grantable Sanctum **abilities**. `register()` accepts key→label or a plain key list; `all()`/`keys()`.
- **Self-service** (`TokenController`, **no admin ability** — tokens are personal): `index` (caller's tokens + scope catalog, never plaintext), `store` (creates via `$user->createToken($name, $abilities)`, returns `plainTextToken` **once**), `destroy` (revokes one of the caller's own tokens). `tokenableUser()` aborts 401 if unauthenticated, 500 if the model lacks `HasApiTokens`. When the catalog is non-empty, `store` requires ≥1 declared scope and `Rule::in` rejects others (422); empty catalog → defaults to `['*']`.
- **Routes** (team-aware prefix, `web`+`auth`): `GET/POST {prefix}/tokens`, `DELETE {prefix}/tokens/{token}`. Registered in `registerTokens()` (only when `tokens.enabled`). No migration (uses Sanctum's `personal_access_tokens`). No permission feature.
- **DTO**: `TokenData` (`fromModel(Model)` — typed loosely so the package never hard-depends on sanctum; plaintext never serialized): id, name, abilities, lastUsedAt, createdAt.
- **Vue (published)**: `KinetixTokenManager` (list + create form with `KinetixCheckbox` scope picker via `inputClass`/`buttonVariants`/`KinetixLabel`; reveal-once copy banner; revoke), `useKinetixTokens`, type `KinetixToken`, i18n `token_*`/`tokens_title`.
- **Enforcement is standard Sanctum** (Kinetix only issues tokens): `auth:sanctum` + `ability:posts.write` middleware, or `$user->tokenCan(...)`.
- laravel/sanctum is a dev dep + `suggest`. Tests: `TokensTest` (creates `users` + `personal_access_tokens` tables, `TokenUser` with `HasApiTokens`), `useKinetixTokens.spec.ts`.
- Full guide: `docs/tokens.md`.

---

## 21. Kinetix Onboarding (optional, first-run UX)

First-run experience. Roadmap v0.15.0. Three pieces: a backend-driven **checklist**, a frontend-only **empty state**, and a frontend-only **product tour**. Config block `onboarding` (`enabled`, `teams`). Migration `kinetix_onboarding` (tag `kinetix-onboarding-migrations`).

- **Checklist steps** (`OnboardingStep` fluent VO; registry `OnboardingStepRegistry` singleton; facade `KinetixOnboarding::step($key,$title)->description()->cta($label,$href)->icon()->completedUsing(fn($user)=>bool)`): a step with `completedUsing` is **auto** (completion computed live, never persisted); without it it's **manual** (persisted when ticked). Re-registering a key replaces it.
- **Manager** (`OnboardingManager`, singleton): `for($user)` → `OnboardingData` (steps as `OnboardingStepData{key,title,description,ctaLabel,ctaHref,icon,completed,manual}`, completedCount, total, complete, dismissed) by merging persisted manual completions (`OnboardingProgress` model, one `kinetix_onboarding` row per user; `team_id` when `onboarding.teams`) with live `isAutoCompleted($user)`. `complete($user,$key)` (no-op if key unknown), `uncomplete`, `dismiss`.
- **Self-service** (`OnboardingController`, **no admin ability**): `GET {prefix}/onboarding`, `POST .../complete` (`{step}`), `POST .../dismiss`. Team-aware prefix. `registerOnboarding()` only when `onboarding.enabled`. Aborts 401 unauthenticated.
- **Vue (published)**: `KinetixOnboardingChecklist` (progress bar, per-step icon/CTA/mark-done, dismiss; hides when dismissed and — default `hideWhenComplete` — when complete), `useKinetixOnboarding` (`state/load/complete/dismiss`). `KinetixEmptyState` (pure: `icon`/`title`/`description` + default slot for CTAs). `KinetixTour` + `useKinetixTour(id,steps)` (dependency-free; spotlights `target` selectors via `getBoundingClientRect`, Teleport overlay, next/back/skip; **auto-starts once per id** via localStorage `kinetix.tour.<id>`; `:auto=false` + exposed `start()`/`reset()`). i18n `onboarding_*`/`tour_*`.
- Tests: `OnboardingTest` (auto vs manual completion, persistence, per-user isolation, dismiss, 401), `useKinetixOnboarding.spec.ts`, `useKinetixTour.spec.ts`.
- Full guide: `docs/onboarding.md`.

---

## 22. Kinetix Wizard (multi-step forms + standalone + gating)

Roadmap v0.17.0. Two surfaces sharing one Vue core (`KinetixWizard`):

- **Form layout** (`Wizard::make()->variant()->orientation()->fullWidth()->slug()->steps([Step::make($label)->icon()->description()->columns()->schema([...])])`): serializes `type:'wizard'` (`variant`, `orientation`, `fullWidth`, `slug`, `schema` = `type:'wizard-step'` children). Rendered by `KinetixFormWizard` which wraps `KinetixWizard`, feeding each step's children through `KinetixFormSchema`. Advancing is **gated on required fields** of the current step (uses `FormFieldData.isRequired`, set from the field's `required` rule; client gate only — server validation still runs on submit).
- **Standalone `<KinetixWizard>`** (published, page-usable): props `steps:KinetixWizardStep[]` (`{key?,label,description?,icon?}`), `variant` (**`stepper`** (default, v0.32.0) `|default|simple|vertical|panels|gradient`), `orientation` (`horizontal|vertical`, for the `stepper` variant), `fullWidth` (default true, v0.47.0 — horizontal indicator stretches to fill + distributes evenly; `false` = compact, content-sized, centered; applies to `stepper`/`default`/`gradient`/`panels`; no-op vertical; on the form layout via `Wizard::make()->fullWidth(false)`), `slug?`, `step?` (v-model:step), `linear` (default true), `beforeNext(fromIndex)=>bool|Promise` guard. Step content via slot named per `step.key` or scoped `#default="{step,index,stepKey}"`; `#actions="{next,prev,finish,isFirst,isLast,busy,current}"` overrides the nav bar. Events `update:step`/`step-change`/`finish`. **`stepper`** = the official shadcn/Reka Stepper (`reka-ui` `StepperRoot/Item/Trigger/Indicator/Title/Description/Separator`, controlled via `:model-value="current+1"`, `data-[state=active|completed]` styling, `orientation` horizontal/vertical) — default for both the standalone component and the form-layout Wizard (`Wizard.php` default `'stepper'`). Other variants: numbered circles+connectors / progress bar+counter / left rail / filled pills / gradient. Indicator jumps limited to reached steps when `linear`.
- **Gating** (config `wizards`: `enabled`, `teams`, `gates` = slug→route name; migration `kinetix_wizard_completions`, tag `kinetix-wizards-migrations`): middleware alias **`kinetix.wizard:<slug>`** (`EnsureWizardCompleted`, always aliased) redirects to `gates[slug]` route until `WizardManager::hasCompleted($user,$slug)`; no-ops when unauthenticated / slug unconfigured / already on the target route (loop-safe). `WizardManager` (singleton): `complete`/`hasCompleted`/`reset`, per-user or per-team. Self-service endpoints `GET {prefix}/wizards/{slug}` (status) + `POST .../complete` (only when `wizards.enabled`). `<KinetixWizard slug=...>` calls `useKinetixWizard().complete(slug)` on finish → opens the gate.
- i18n `wizard_*`. Tests: `WizardLayoutTest`, `WizardGateTest`, `KinetixWizard.spec.ts`. Full guide: `docs/wizard.md`.

---

## 23. Kinetix GDPR (self-service data export + account deletion)

Roadmap v0.19.0. Config block `gdpr` (`enabled`, `deletion` = anonymize|delete, `require_password`, `anonymize` map column→value|closure, `redirect`). No migration (reuses the exports download route + notifications). **Self-service, no admin ability.**

- **Registry/facade** (`GdprRegistry` singleton; `KinetixGdpr::export($name, fn($user)=>mixed)` registers data sections; `KinetixGdpr::deleteUsing(fn($user)=>void)` overrides deletion).
- **`GdprManager`** (singleton): `collect($user)` runs sections (Arrayable→toArray) → `array`; `purge($user)` = custom handler ?? (`deletion==='delete'` → `$user->delete()`) ?? anonymize (apply map, save, soft-delete if SoftDeletes).
- **`GdprController`** (self-service): `export` dispatches `GdprExportJob`; `destroy` validates password via `Hash::check` against `getAuthPassword()` (when `require_password`), `purge`s, `Auth::logout()` + session invalidate, returns `{redirect}`. Aborts 401 unauthenticated, 422 on wrong password.
- **`GdprExportJob`** (queued): `collect` → pretty JSON → store under `kinetix-exports/<uuid>.json` on the Kinetix disk → notify with a download `Action` (reuses `kinetix.exports.download` route + Crypt token, same as `ExportProcessor`).
- **Routes** (team-aware prefix, only when `gdpr.enabled`): `POST {prefix}/gdpr/export`, `POST {prefix}/gdpr/delete`.
- **Vue (published)**: `KinetixGdprPanel` (Reka `Dialog` confirm, password field when `:require-password`; on delete → `router.visit(redirect)`), `useKinetixGdpr` (`exportData`/`deleteAccount(password?)`). i18n `gdpr_*`.
- Tests: `GdprTest` (export dispatch, collect, password gate, anonymize/delete/custom, job writes JSON, 401), `useKinetixGdpr.spec.ts`. Full guide: `docs/gdpr.md`.

---

## Component screenshots (docs)

Doc screenshots are generated, not hand-captured. `gallery/` is a standalone Vite app (`vite.gallery.config.ts`, `npm run gallery:dev`) that renders ONE component per request (`?s=<name>&theme=light|dark`) from `gallery/specimens.ts` (`{name,title,component,props,slots?,width?}`); `@inertiajs/vue3` + `@/composables/useKinetixHttp` are aliased to stubs in `gallery/stubs/` (so self-fetching components get fixtures), real `en` messages come from `scripts/gen-gallery-messages.php` (Laravel `:x`→vue-i18n `{x}`), and shadcn tokens load via `gallery/app.css` (`@import tailwindcss` + `@source "../resources/js"` + `kinetix.css`). `scripts/screenshots.mjs` (`npm run screenshots`) boots the gallery via Vite SSR, Playwright-captures each specimen in light+dark at 2× into `docs/public/screenshots/<name>[-dark].png`. Embed in the **relevant feature doc** (NOT a central gallery page) with the global `<Screenshot name="<name>" alt="…" />` component (`docs/.vitepress/theme/Screenshot.vue` + `theme/index.ts` registers it; CSS in `custom.css` shows `<name>.png` in light and `<name>-dark.png` in dark via `html.dark`). Teleported overlays (modals, Spotlight, drawers, file-preview) are NOT captured — they render outside the cropped `#specimen` frame. To add: append a specimen to `gallery/specimens.ts`, rerun, drop a `<Screenshot>` where the component is documented. Dev-only (devDeps `playwright`/`tailwindcss`/`@tailwindcss/vite`); never published. Browsers: `npx playwright install chromium`.

## 24. Kinetix Accessibility (per-user a11y preferences + SR primitives)

Roadmap v0.24.0. Config block `accessibility` (`enabled`, `defaults`). Migration `kinetix_accessibility` (one row per user, JSON prefs; tag `kinetix-accessibility-migrations`). **Self-service, no admin ability.**

- **Prefs** (the most-used a11y toggles): `reducedMotion`, `highContrast`, `textSize` (normal|large|x-large), `underlineLinks`, `enhancedFocus`. `AccessibilityManager::for($user)` (merge over config defaults) / `update($user,$prefs)`; `AccessibilityData` DTO normalizes (textSize enum). `AccessibilityController` `index`/`update` → `GET/POST {prefix}/accessibility` (validated, 401 if unauthenticated, 422 bad textSize).
- **Shared + flash-free apply**: `Inertia::share('kinetix_accessibility', ...)` (user prefs or config defaults). The **`KinetixAccessibility` Vue plugin** (`plugins/kinetixAccessibility.ts`, `app.use(...)`) injects the a11y CSS (`<style id=kinetix-a11y>`) + applies classes on `<html>` BEFORE mount, reading the initial `[data-page]` prop and a localStorage mirror (`kinetix.accessibility`). Classes: `kx-reduce-motion`, `kx-high-contrast`, `kx-text-large`/`kx-text-x-large`, `kx-underline-links`, `kx-enhanced-focus` (theme-agnostic CSS using `currentColor` outlines; text-size scales the rem root).
- **Vue (published)**: `KinetixAccessibilityPanel` (text-size segmented buttons + 4 KinetixCheckbox toggles), `useKinetixAccessibility` (`{prefs, set}` — optimistic apply + persist + localStorage; exports `applyKinetixAccessibility`; `set()` server-persist is **best-effort** in try/catch so guest pages — login/wizard — keep prefs client-side). `KinetixSkipLink` (sr-only-until-focus skip-to-content). `useKinetixAnnounce` (`announce(msg, assertive?)` → shared ARIA live region appended to body, inline visually-hidden styles).
- **Quick-menu + theme toggle** (v0.37.0): `KinetixAccessibilityMenu` (reka `PopoverRoot/Trigger/Content`, Accessibility icon → popover with the same controls; usable on login/wizard — guest-safe). `KinetixModeToggle` (reka `DropdownMenuRoot`, Sun/Moon icon w/ rotate-scale transition → Light/Dark/System) backed by `useKinetixAppearance` (`{appearance, resolved, setAppearance}`) which **mirrors the starter kit's contract** — same `appearance` localStorage key + cookie, toggles `html.dark`, `system` via matchMedia — so it stays in sync with the starter kit Appearance settings without importing the host's `useAppearance`. i18n `toggle_theme`/`appearance_light|dark|system`.
- i18n `a11y_*`/`skip_to_content`. Tests: `AccessibilityTest` (defaults, persist+normalize, invalid textSize 422, per-user isolation, 401), `useKinetixAccessibility.spec.ts` (class apply), `KinetixThemeA11y.spec.ts` (mode toggle storage/html.dark + guest-safe a11y set). Full guide: `docs/accessibility.md`.

---

## 25. Kinetix Connected Accounts (optional, requires laravel/socialite)

Roadmap v0.27.0. Complete **social-auth** feature (the Vue starter kit ships NO OAuth → this is a full feature, not a complement). Config block `connected_accounts` (`enabled`, `login_enabled`, `prevent_lockout`, `redirect`/`login_redirect`/`login_failure_redirect`, `providers`). Migration `kinetix_connected_accounts` (one row per user+provider; unique `[provider,provider_id]` + `[user_id,provider]`; `token`/`refresh_token` **encrypted** casts; tag `kinetix-connected-accounts-migrations`). **Self-service, no admin ability.** The User model needs **no trait** (queried by `user_id`).

- **Providers**: `ConnectedAccountProviderRegistry` (seeded from config; `KinetixConnectedAccounts::providers([key => ['label','icon','color']])`; string value = label, icon defaults to key). `KinetixConnectedAccounts::providers/resolveUserUsing/createUserUsing/flush`.
- **`ConnectedAccountManager`**: `for($user)` (DTO list), `link($user,$provider,$socialUser)` (upsert; throws `AccountAlreadyLinkedException` if the identity belongs to another user), `unlink($user,$id)` (aborts 422 via `wouldLockOut` — no password + last account), `hasPassword($user)` (`getAuthPassword()`), `setPassword`, `resolveLoginUser`/`createLoginUser` (login flow; default creator makes a **passwordless** user → requires nullable `password` column).
- **`ConnectedAccountController`** routes (team-aware for the authed group; login group is `web`-only, no team prefix, only when `login_enabled`): `GET /connected-accounts` index (accounts + providers[{key,label,icon,color,linked}] + `hasPassword`), `GET redirect/{provider}` + `GET callback/{provider}` (link to current user; uses `->redirectUrl()` override, team-aware callback URL), `POST password` (set/change — `current_password` required only when `hasPassword`), `DELETE {account}`, plus `GET login/redirect|callback/{provider}` (guest find-or-create + `Auth::login(remember:true)` + link). `redirectUrl()` isn't on the Socialite `Provider` contract → `@phpstan-ignore method.notFound`.
- **Vue (published)**: `KinetixConnectedAccounts` (provider rows with built-in `github`/`google` brand SVGs, fallback = initial; Connect = `<a :href>` full-page OAuth, Disconnect = inline confirm; set/change-password form for social-only users), `useKinetixConnectedAccounts` (`{accounts, providers, hasPassword, loading, load, connectUrl, disconnect, setPassword}`). DTO `ConnectedAccountData` (never serializes tokens). Types `KinetixConnectedAccount`/`KinetixConnectedProvider`.
- **Social buttons + brand icons** (v0.29.0): local SVG brand components in `resources/js/icons/brands/` (`Brand{Github,Google,Microsoft,Gitlab,Bitbucket,Facebook,X,Apple,Discord,Twitch,Generic}.vue`) — sourced from simple-icons (devDep; CC0, single-path currentColor) EXCEPT Microsoft (hand-authored 4-color mark) + Generic (lucide-style link fallback). Registry `@/icons/brands` (`brands` map + `brandFor(key)` → `{label, icon, color}`, falls back to Generic + title-cased label). `KinetixSocialButton` (props `provider`, `mode` login|link, `label`, `branded`, `block`, `variant`, `href`) renders icon+label as a full-page `<a>` to `connected-accounts/login/redirect/{p}` or `/redirect/{p}`. `KinetixConnectedAccounts` now renders icons via `brandFor`. i18n `continue_with` (`:provider`). To add brands: re-run the simple-icons generator or hand-author an SFC + add to the registry.
- i18n `connected_account_*` + `password_*` (en/es/fr/pt). Tests: `ConnectedAccountsTest` (link, reject foreign identity, index, unlink, lockout-guard, set-password, unknown provider 404, guest login find-or-create+passwordless — registers `SocialiteServiceProvider` + mocks `Socialite::driver`), `ConnectedAccountProviderRegistryTest`, `KinetixConnectedAccounts.spec.ts`. Full guide: `docs/connected-accounts.md`. Pairs with `docs/starter-kit.md`.

---

## 26. Kinetix Browser Sessions (optional, device management)

Roadmap v0.28.0. Modern shadcn take on Jetstream's browser-sessions (no `jenssegers/agent` dep — ships a tiny `Sessions\UserAgentParser`). Config block `sessions` (`enabled`, `require_password`). **No migration** — reads Laravel's `sessions` table → requires `SESSION_DRIVER=database`. **Self-service, no admin ability.**

- **`BrowserSessionManager`**: `usesDatabaseDriver()`, `for($user,$request)` (queries `session.table`/`session.connection` via `DB::connection`, parses UA, current device first; uses `$request->hasSession() ? ->getId() : null` so it's safe in stateless contexts), `logoutOthers($user,$request)` (deletes user's rows where `id != currentId`, keeps current). `BrowserSessionData` DTO (`id,ipAddress,browser,platform,device,isCurrentDevice,lastActive` ISO).
- **`SessionController`**: `GET {prefix}/sessions` index (sessions + `databaseDriver` + `requiresPassword`), `DELETE {prefix}/sessions/others` (validates `current_password` only when `require_password` && user `hasPassword`). Team-aware prefix.
- **Vue (published)**: `KinetixSessions` (device icons Monitor/Smartphone/Tablet + ShieldCheck "this device" badge from `@lucide/vue`; relative last-active; "Log out other sessions" with inline password prompt; non-database-driver notice), `useKinetixSessions` (`{sessions, databaseDriver, requiresPassword, loading, load, logoutOthers}`).
- i18n `session*` keys (en/es/fr/pt). Tests: `BrowserSessionsTest` (manager direct for current-device detection — builds a `Request` with `setLaravelSession` + a valid 40-char id; logout-others keeps current; HTTP password-gate 422/200; non-db driver), `UserAgentParserTest`, `KinetixSessions.spec.ts`. Full guide: `docs/sessions.md`.

---

## 27. Kinetix Comments (optional, polymorphic threaded comments)

Roadmap v0.38.0. Config block `comments` (`enabled`). Migration `kinetix_comments` (`user_id`, `commentable_type/id` morphs, nullable `parent_id` for replies, `body`; tag `kinetix-comments-migrations`). **Self-service**: anyone who may view a commentable can read/post; each user edits/deletes only their own.

- **Allowlist**: `KinetixComments::for([Post::class, ...])` → `CommentRegistry` (singleton; `resolve($type)` maps a client morph type/alias → registered class via `Relation::getMorphedModel`, else null). The controller 404s unregistered types — keeps endpoints off arbitrary records.
- **`Comment` model**: `morphTo commentable`, `hasMany replies` (self `parent_id`), `belongsTo author` (resolves the configured `auth.providers.*.model`). `CommentManager`: `for($commentable,$user)` (groups by stringified `parent_id`, builds a 1-level tree of `CommentData`), `create/update/delete` (delete also removes replies). `CommentData` (`#[TypeScript]`): id, body, author{Id,Name,Avatar}, parentId, createdAt, `edited` (updated≠created), `editable` (author === current user), `replies[]`.
- **`CommentController`** (team-aware `{prefix}/comments`): `index`/`store` take `commentable_type`+`commentable_id` (resolved+authorized via registry + optional `Gate::denies('view')` when a policy exists); `store` validates body (max 5000) + parent must be a top-level comment of the same commentable; returns the refreshed tree. `update`/`destroy {comment}` gated to the author (403 otherwise). NOTE: a private test helper must not be named `post()` (collides with Testbench) — use `makePost()`.
- **Vue (published)**: `KinetixComments` (props `commentableType`,`commentableId`): composer + threaded list (avatar img or initials, relative time, `edited` badge), inline reply/edit/delete on own. `useKinetixComments(type,id)` → `{comments, loading, load, post, edit, remove}` (server returns the full tree after mutations). i18n `comment_*`. Tests: `CommentsTest` (post+list, threaded reply, author-only edit/delete 403, delete cascades replies, unregistered type 404), `KinetixComments.spec.ts`. Full guide: `docs/comments.md`.

---

## 28. Kinetix Tags (optional, polymorphic tagging)

Roadmap v0.40.0. Config block `tags` (`enabled`). Migration: `kinetix_tags` (`team_id` nullable, `name`, `slug`, unique `[team_id, slug]`) + `kinetix_taggables` morph pivot (unique `[tag_id, taggable_type, taggable_id]`); tag `kinetix-tags-migrations`. Real tags (vs the `TagsInput` field which is just a string array on the record). Team-scoped automatically by `team_id` when `kinetix.teams`.

- **Trait** `HasKinetixTags` (public API): `tags()` = `morphToMany(Tag, 'taggable', 'kinetix_taggables', 'taggable_id', 'tag_id')`. Add to taggable models. (Excluded from phpstan `trait.unused` in `phpstan.neon`, like the other host-consumed traits; `$taggable->tags()` calls in `TagManager` are covered by a `Model::tags()` `ignoreErrors` path entry — follow that convention, NOT inline ignores.)
- **`TagManager`**: `for($taggable)` (names), `suggest($q,$teamId,$limit)` (autocomplete), `sync($taggable,$names,$teamId)` (find-or-create by `Str::slug`, dedup, pivot sync), `findOrCreate`, `all($teamId)`. **`TagRegistry`** allowlist (`resolve($type)` also requires the model `class_uses_recursive` includes `HasKinetixTags`). `KinetixTags::for([...])`.
- **`TagController`** (team-aware `{prefix}/tags`): `GET /` index (a taggable's tags), `GET suggest?q=`, `POST sync`. Resolves taggable via registry; honors host `view` (read) / `update` (write) policy via `Gate::getPolicyFor`. `teamId()` from `request()->route('current_team')` when teams on.
- **`TagFilter`** (table, extends `MultiSelectFilter`): `getOptions()` = all tag names; `apply()` → `whereHas('tags', whereIn slug)` (table model needs the trait).
- **Vue (published)**: `KinetixTags` (props `taggableType`,`taggableId`): chips + autocomplete dropdown (debounced `suggest`) + create-on-Enter + backspace-removes-last; syncs every change. `useKinetixTags(type,id)` → `{tags, loading, load, suggest, sync}`. i18n `tag_placeholder`/`tag_remove`. Tests: `TagsTest` (sync find-or-create + dedup, slug reuse, suggest, index, unregistered 404, TagFilter whereHas), `KinetixTags.spec.ts`. Full guide: `docs/tags.md`.

---

## 29. Kinetix Notification Preferences (optional, opt-in matrix)

Roadmap v0.41.0. Config block `notification_preferences` (`enabled`, `channels` key=>label default mail/database/broadcast, `types` key=>label). Migration `kinetix_notification_preferences` (one row/user, JSON `preferences` = `{type:{channel:bool}}`, only opt-outs stored; tag `kinetix-notification-preferences-migrations`). **Defaults to enabled** so new types/channels are on until opted out. **Self-service.**

- **`NotificationTypeRegistry`** (singleton, seeded from config + `KinetixNotificationPreferences::types([...])`). **`NotificationPreferenceManager`**: `channels()` (from config), `for($user)` (matrix `{channels:[{key,label}], types:[{key,label,channels:{ch:bool}}]}`), `update($user,$type,$channel,$enabled)` (firstOrNew, merge JSON), `allows($user,$type,$channel)` (default true), `channelsFor($user,$type,$channels)` (filter). NOTE: in `stored()`, `first()` then explicit `if null return []` (larastan flags `?->` as `nullsafe.neverNull`).
- **`KinetixNotificationPreferences`** static: `types()`, `allows()`, `channelsFor()` — the gating API for a Notification's `via()`: `return KinetixNotificationPreferences::channelsFor($notifiable, 'orders', ['mail','database']);`.
- **`NotificationPreferenceController`** (team-aware `{prefix}/notification-preferences`): `GET /` index, `POST /` update (validates type ∈ registry, channel ∈ config channels, enabled boolean).
- **Vue (published)**: `KinetixNotificationPreferences` (table: rows=types, cols=channels, `KinetixCheckbox` cells persisting on change). `useKinetixNotificationPreferences` → `{matrix, loading, load, set}`. i18n `notification_prefs_*`. Tests: `NotificationPreferencesTest` (matrix defaults, opt-out persist, unknown type/channel 422, channelsFor filter), `KinetixNotificationPreferences.spec.ts` (uses `findAllComponents(KinetixCheckbox)` + `$emit('change')` — reka checkbox is a `button[role=checkbox]`, not `<input>`). Full guide: `docs/notification-preferences.md`.

---

## 30. Kinetix Saved Views (optional, per-user table presets)

Roadmap v0.42.0. Config block `saved_views` (`enabled`). Migration `kinetix_saved_views` (`user_id`, `team_id` nullable, `view_key`, `name`, JSON `state`, `is_default`; tag `kinetix-saved-views-migrations`). **Self-service** — each user manages only their own. Team-scoped automatically when `kinetix.teams`.

- **Table integration**: `Table::saveViews(?string $key = null)` (key defaults to model class) → `$savedViewsKey` → `TableData.savedViewsKey`. When set, `KinetixTable` renders `<KinetixSavedViews>` in the toolbar. The state captured/restored = `{search, sort, direction, perPage, filters, columns:[visible names]}` (`currentViewState` computed; `applyView()` sets `searchQuery`/`activeFilters`/`visibleColumnNames` then `triggerReload`). Column visibility is client-only; the rest round-trips via the query string.
- **`SavedViewManager`**: `for($user,$key,$teamId)` (default first), `create`/`update`/`delete`, `makeDefault` (clears others), `ownedBy`. `SavedView` model (JSON `state`, bool `is_default`), `SavedViewData` DTO.
- **`SavedViewController`** (team-aware `{prefix}/saved-views`): `GET ?key=` index, `POST` store, `PUT/DELETE {view}`, `POST {view}/default`. Each route 404s views not owned by the user; mutations return the refreshed list.
- **Vue (published)**: `KinetixSavedViews` (reka DropdownMenu: apply on click, star=default, trash=delete, "Save current view" prompts a name + captures the parent's `currentState`; emits `apply(state)`; applies the default on mount). `useKinetixSavedViews(viewKey)` → `{views, loading, load, create, update, remove, setDefault}`. i18n `saved_view*`. Tests: `SavedViewsTest` (store+list, key/user scoping, set-default clears others, foreign-user 404, delete), `KinetixSavedViews.spec.ts` (default-apply on mount + composable create — dropdown content is teleported, assert trigger only). Full guide: `docs/saved-views.md`.

---

## 31. Kinetix Kanban (optional, drag-and-drop board)

Roadmap v0.43.0. Server-driven board over an Eloquent query, like Tables — no migration, no config flag. The move route is **always registered** inside `registerTableRoutes()` (`POST {prefix}/tables/kanban-move`, name `kinetix.tables.kanban-move`), guarded by a signed descriptor.

- **`Kanban` builder** (`Kanban::make($queryOrModel)`): `->statusColumn('status')`, `->statuses(['key' => 'Label' | ['label'=>,'color'=>]])`, `->cardTitle(attr|Closure)`, `->cardDescription(attr|Closure|null)`, `->query(Closure)`, `->heading()`. `toData()` → `KanbanData{heading, columns:[KanbanColumnData{key,label,color,cards:[KanbanCardData{id,title,description}]}], model}` where `model` = `Crypt::encrypt(['model','statusColumn','statuses'=>array_keys])`.
- **Move endpoint**: decrypts the descriptor, validates `status ∈ statuses` (403 otherwise), 400 on bad signature, 404 missing record, then writes `statusColumn`. Mirrors the cell-update guard — only the baked status column + declared statuses are writable.
- **Vue (published)**: `KinetixKanban` (props `kanban`): columns + draggable cards via **native HTML5 DnD** (`draggable`, `@dragstart`/`@dragover.prevent`/`@drop`); optimistic move with revert on failure (`kanban_move_failed` toast) + `router.reload()` on success. Keeps a local reactive copy of columns. i18n `kanban_empty`/`kanban_move_failed`. Tests: `KanbanTest` (grouping into columns, move updates status, status-outside-board 403, invalid-signature 400), `KinetixKanban.spec.ts` (renders columns/cards; drag→drop posts the move). Full guide: `docs/kanban.md`.

---

## 32. Kinetix Calendar (optional, month-view event scheduler)

Roadmap v0.44.0. Server-driven builder like Kanban — no migration, no routes, no config (read-only display; month navigation is client-side). **NOTE: the Vue component is `KinetixEventCalendar`** — `KinetixCalendar` already exists (the date-picker's single-date selector built on reka `CalendarRoot`); do not confuse/overwrite them.

- **`Calendar` builder** (`Calendar::make($queryOrModel)`): `->dateColumn('starts_at')` (default `date`), `->endColumn('ends_at')` (optional, inclusive multi-day), `->title(attr|Closure)`, `->color(attr|Closure|null)`, `->url(Closure)`, `->query(Closure)`, `->heading()`. `toData()` → `CalendarData{heading, events:[CalendarEventData{id,title,start:Y-m-d,end?,color?,url?}]}` (dates via `Carbon::parse(...)->toDateString()`).
- **Vue `KinetixEventCalendar`** (props `calendar`, `weekStartsOn` 0–6 default 1, `locale`): builds a 42-cell (6-week) grid with plain `Date`; month/weekday labels via `Intl.DateTimeFormat(locale)`; an event shows on a day when `start === date` or (with end) `start <= date <= end`. Colored chips (default `#3b82f6`), `url` events render as `<a>`, `+N more` beyond 3/day, today highlighted, out-of-month dimmed. Emits `event-click(event)` / `day-click(isoDate)`; month nav (`Today`/prev/next) client-side. i18n `calendar_today/prev/next/more`.
- Tests: `CalendarTest` (maps events→ISO + title/color/url, multi-day end, `query` scope), `KinetixEventCalendar.spec.ts` (42 cells + weekday headers, event placement + url anchor, prev/next nav — select nav buttons by `aria-label`, which under the test i18n `missing` handler is the full key `kinetix.calendar_next`). Full guide: `docs/calendar.md`.

---

## 33. Kinetix Announcements (optional, "what's new" feed)

Roadmap v0.46.0. Config block `announcements` (`enabled`). Migration: `kinetix_announcements` (title, body, level info|feature|fix, nullable `published_at`) + `kinetix_announcement_views` (one row/user, `seen_at`); tag `kinetix-announcements-migrations`. **Per-user unread via a single last-seen timestamp** (an entry is new when `published_at > seen_at`; all new until first open). **Self-service** read; publishing is app-side.

- **`AnnouncementManager`**: `feed($user,$limit=20)` (published-only, newest first, `isNew` flag), `unreadCount($user)`, `markSeen($user)` (updateOrCreate view), `create(title,body,level,publishedAt?)` (defaults `published_at`=now). **`KinetixAnnouncements::publish(...)`** static for seeders/deploys. `Announcement` (casts `published_at`) + `AnnouncementView` models, `AnnouncementData` DTO.
- **`AnnouncementController`** (team-aware `{prefix}/announcements`): `GET /` index (`announcements`+`unread`), `POST seen`.
- **Vue (published)**: `KinetixAnnouncements` (reka Popover, Megaphone trigger + unread badge; opening marks seen → badge clears; items show a "new" dot + level chip + body `whitespace-pre-line` + date). `useKinetixAnnouncements` → `{announcements, unread, loading, load, markSeen}`. i18n `announcements_title/empty/new`. Tests: `AnnouncementsTest` (published-only feed + all-new for fresh user, markSeen clears, re-new after later publish via `$this->travel()`), `KinetixAnnouncements.spec.ts` (badge + mark-seen on open). Full guide: `docs/announcements.md`.

---

## 34. Kinetix Locale / Language switcher (optional)

Roadmap v0.48.0. Config block `locale` (`enabled`, `locales` = `code=>native label`, `store_on_user`, `session_key`). The host app owns the vue-i18n instance (`locale: page.props.locale`); this feature only resolves/applies/persists the active locale + provides the switcher UI. **Auth-optional** (works on the login screen).

- **`LocaleManager`** (singleton): `locales()`/`options()` (`[{code,label}]`), `isSupported()`, `current()` (`App::getLocale()`), `resolve($user?)` (user `locale` column → session → null), `apply($user?)` (`App::setLocale`), `set($code,$user?)` (persist session + user column when `Schema::hasColumn` + `store_on_user`, then `App::setLocale`; returns false for unsupported). `KinetixLocale` static facade (`set/current/options`).
- **`SetKinetixLocale`** middleware (always aliased **`kinetix.locale`**; app adds it to the web group): applies the persisted locale per request; no-ops when disabled / nothing stored. **`LocaleController@update`** `POST {prefix}/locale` (`web` only, no `auth`; team-aware prefix): validates code, `manager->set()`, abort 422 unsupported.
- **Inertia share `kinetix_locale`**: `{enabled, current, locales:[{code,label}]}`. Optional migration `add_locale_to_users_table` (nullable `locale` after `email`, guarded by `hasColumn`), tag `kinetix-locale-migrations`.
- **Vue (published)**: `KinetixLanguageSwitcher` (reka DropdownMenu, `Languages` trigger; prop `showLabel` shows the active code e.g. "EN"; items = locales with `Check` on current). `useKinetixLocale` → `{locales, current, saving, setLocale}` — `setLocale` flips vue-i18n `locale` optimistically + POSTs + `router.reload()`, rolling back on failure; no-ops same locale. i18n `language`. Tests: `LocaleTest` (session+user persistence, 422 unsupported, resolve precedence, apply, options), `KinetixLanguageSwitcher.spec.ts` (trigger a11y + showLabel + composable persist/no-op/rollback — dropdown content is teleported so not asserted). Full guide: `docs/locale.md`.

---

## 35. Kinetix Team Switcher (optional, multi-team dropdown)

Roadmap v0.49.0. Config block `team_switcher` (`enabled`, `teams_relation`='teams', `current_relation`='currentTeam', `name_attribute`='name', `switch_route`='teams.switch', `create_route`=null). **Kinetix does NOT own the Team model** — the official starter kit has no teams; this is convention-based and host-agnostic.

- **`TeamSwitcherManager`** (singleton): `payload()` reads `auth()->user()->{teams_relation}` + `{current_relation}` (via `getAttribute`, so test with `setRelation`), maps each team to `{id, name, url, current}` where `url = route(switch_route, $team->getRouteKey())` guarded by `Route::has` (→ `null` if missing), plus `current` `{id,name}` and `createUrl`. Empty `{enabled:false,…}` for guests / when off. **No backend switch route** — Kinetix delegates to the host's `switch_route`; switching is the host's job (`$user->switchTeam()`).
- **Inertia share `kinetix_teams`** (always shared; payload gated internally). Optional `create_route` → "New team" entry.
- **Vue (published)**: `KinetixTeamSwitcher` (reka DropdownMenu, `Users`+name+`ChevronsUpDown` trigger; items with `Check` on current; `Plus` "New team" when `createUrl`). `useKinetixTeams` → `{teams, current, createUrl, switchTeam}` — `switchTeam` `router.visit(team.url)`, no-ops the current team / null url. i18n `teams_switch/select/new`. Tests: `TeamSwitcherTest` (payload+switch urls+current flag via `setRelation`, disabled/guest empty, null url when route missing), `KinetixTeamSwitcher.spec.ts` (trigger shows current + composable visit/no-op). Full guide: `docs/team-switcher.md`.

---

## 36. Resource Breadcrumbs (auto-derived; complements the starter kit)

Roadmap v0.50.0. The starter kit owns the `<Breadcrumbs>` component — Kinetix does **not** ship one; it auto-derives the *trail* from a Resource and the generator shares it as a page prop. No config block.

- **`Resource::breadcrumbs($operation, ?Model $record): array<{title,href}>`** (in `src/Resources/Resource.php`) for `index`/`create`/`edit`/`show`. Built from: `getNavigationLabel()` (root, links to `{base}.index`), `getRecordTitle($record)` (defaults `name`→`title`→`label`→`#id`; override `$recordTitleAttribute`), `getRouteBaseName()` (defaults plural-kebab of model basename = generator's route names; override `$routeBaseName`). `resolveHref()` uses `RouteFacade::getRoutes()->getByName()` + `parameterNames()` to fill the record key + `current_team` (from `request()->route('current_team')`); try/catch → falls back to `request()->fullUrl()` so it never throws. Last crumb's href = current URL. Create/Edit labels via `__('kinetix.breadcrumb_create'|'breadcrumb_edit')`.
- **Generator (`MakeResourceCommand`)**: every `inertia(...)` action now passes `'breadcrumbs' => {Resource}::breadcrumbs('index'|'create'|'edit', $record?)`; generated Vue pages declare `breadcrumbs?: KinetixBreadcrumb[]` (typed, fed to the host layout's `<Breadcrumbs>` — wiring documented, not auto-imposed). TS type `KinetixBreadcrumb {title,href}`.
- i18n `breadcrumb_create/edit`. Tests: `ResourceBreadcrumbsTest` (route base/label defaults, index/create/edit/show trails, record-title fallback), `MakeResourceCommandTest` (controller emits `breadcrumbs(...)`). Full guide: `docs/breadcrumbs.md`. **No published Vue component** (reuses the starter kit's).

---

## 37. Kinetix Presence / Online indicators (optional, realtime)

Roadmap v0.51.0. Config block `presence` (`enabled`, `channel`='kinetix-presence', `name_attribute`='name', `avatar_attribute`='avatar_url'). Rides on broadcasting (`@laravel/echo-vue` + `configureEcho`); requires `kinetix:install --broadcasting`.

- **`PresenceManager`** (singleton): `channelName()` (team-suffixed `{channel}.{teamId}` when `kinetix.teams` on, resolved from `request()->route('current_team')` ?? `user->currentTeam->getKey()`), `channelPattern()` (auth pattern with `{team}` placeholder), `memberData($user)` → `{id (getKey), name, avatar}`, `state()` → `{enabled, channel}`.
- **Provider**: `registerPresence()` in boot registers `Broadcast::channel($manager->channelPattern(), fn (Model $user) => $manager->memberData($user))` when enabled + Broadcast class exists (no `routes/channels.php` edit needed). Shares `kinetix_presence` = `state()`.
- **Vue (published)**: `KinetixOnlineUsers` (facepile: up to `max`=5 avatars img/initials, `+N` overflow, green dot + `presence_online` count; props `max`/`showCount`/`channel`; renders nothing without a channel). `useKinetixPresence(channel?)` → `{users, count, isOnline, channel}` — joins via `useEchoPresence`, wires `getChannel().here/joining/leaving`, Map keyed by `String(id)`, `leave()` on unmount. TS types `KinetixPresenceUser`/`KinetixPresenceState`. i18n `presence_online {count}`.
- **Gallery**: needs `@laravel/echo-vue` aliased to `gallery/stubs/echo.ts` (pre-populates `here()`); `kinetix_presence` added to the inertia stub. Tests: `PresenceTest` (channel name ±teams, pattern, memberData ±avatar, state), `KinetixOnlineUsers.spec.ts` (mock echo-vue fake channel → here/joining/leaving membership + facepile overflow/count). Full guide: `docs/presence.md`.

---

## 38. Kinetix Queue Health widget (optional, Horizon-aware)

Roadmap v0.52.0. Config block `queue` (`enabled`, `queues`=[{connection,queue}] monitored when no Horizon, `poll` ms). **Complements, not replaces, the Horizon dashboard** — a glanceable embeddable widget.

- **`QueueMetrics`** (singleton): `snapshot()` → `{horizon, status, throughput, recentJobs, failedJobs, queues:[{name,connection,size,wait}]}`. `horizonAvailable()` = `class_exists('Laravel\Horizon\Horizon')` + bound MasterSupervisorRepository. Horizon path reads repos **via string class names** (`app('Laravel\Horizon\Contracts\...')`, each wrapped in try/catch → null) so phpstan doesn't need Horizon installed: `MetricsRepository::jobsProcessedPerMinute`, `JobRepository::countRecent/countFailed`, `MasterSupervisorRepository::all` (status running|paused|inactive), `WorkloadRepository::get` (name/length/wait). Fallback: `Queue::connection()->size()` per configured queue + `app('queue.failer')->all()` count. Always returns ints (0 on failure).
- **`QueueController@index`** `GET {prefix}/queue` (team-aware), gated by **`Gate::authorize('viewKinetixQueue')`**. Provider `registerQueue()` defines a default gate `fn => app()->environment('local')` only `if (! Gate::has(...))` (host overrides for prod). Shares `kinetix_queue` = `{enabled, poll}`.
- **Vue (published)**: `KinetixQueueStats` (status badge when horizon; tiles throughput/recent/pending(sum sizes)/failed — throughput+recent hidden without horizon; per-queue rows with wait+size; polls via composable, stops on unmount). `useKinetixQueue` → `{snapshot, loading, failed, load, start, stop}` (interval from `kinetix_queue.poll`; poll=0 → one load). TS types `KinetixQueueSnapshot/Row/Config`. i18n `queue_*`.
- **Gallery**: `/queue` fixture in `http.ts` stub + `kinetix_queue` (poll 0) in inertia stub. Tests: `QueueMetricsTest` (fallback snapshot shape, endpoint 200 authorized + 403 unauthorized — needs `actingAs` since Gate denies guests), `KinetixQueueStats.spec.ts` (composable load/fail + component tiles/status/rows). Full guide: `docs/queue.md`.
- **Failed-job retry/delete (v0.58.0)**: snapshot gains `failed` array. `QueueMetrics::failed($limit=10)` reads `app('queue.failer')->all()` (works ±Horizon; `jobName()` parses `displayName`/`data.commandName` from payload, class_basename). `retry($id)` → `Artisan::call('queue:retry', ['id'=>[$id]])`; `forget($id)` → `app('queue.failer')->forget($id)`. Endpoints `POST {prefix}/queue/retry` + `DELETE {prefix}/queue/failed` (gated `viewKinetixQueue`). `useKinetixQueue` gains `retry`/`forget` (call + reload). `KinetixQueueStats` failed-jobs list (RotateCcw retry / Trash2 delete). i18n `queue_retry` (reuse `remove`/`queue_failed`). Tests: `QueueMetricsTest` configures a `database-uuids` failer + `failed_jobs` table to assert `failed()` parsing + forget endpoint; retry endpoint tested with a no-op id.

---

## 39. Kinetix System Health widget (optional, spatie/laravel-health)

Roadmap v0.53.0. Config block `health` (`enabled`, `poll` ms, default 30000). Same shape as the Queue widget; **complements, not replaces** a health page. spatie/laravel-health is an optional dep (guarded by string-class resolution).

- **`HealthMetrics`** (singleton): `snapshot()` → `{available, status, checkedAt, checks:[{name,label,status,message}]}`. `available()` = `class_exists('Spatie\Health\Health')` + bound `ResultStore`. Reads `app('Spatie\Health\ResultStores\ResultStore')->latestResults()` (try/catch → unavailable); maps `storedCheckResults` (`->name/->label/->status/->shortSummary|->notificationMessage`), `checkedAt` from `->finishedAt->toIso8601String()`. `overallStatus()` worst-of: failed/crashed → 'failed', warning → 'warning', else 'ok'. Status strings from spatie: ok|warning|failed|crashed|skipped.
- **`HealthController@index`** `GET {prefix}/health` (team-aware), gated `Gate::authorize('viewKinetixHealth')`. Provider `registerHealth()` defines default gate `fn => environment('local')` if not already defined. Shares `kinetix_health` = `{enabled, poll}` (reuses TS `KinetixQueueConfig` shape).
- **Vue (published)**: `KinetixHealthStatus` (overall badge ok|warning|failed via `health_status_*`; per-check rows with status icon `CheckCircle2`/`CircleAlert`/`CircleX` + tone + summary; unavailable message when `available===false` && no checks; polls via composable, stops on unmount). `useKinetixHealth` → `{snapshot, loading, failed, load, start, stop}` (interval from `kinetix_health.poll`; poll=0 → one load). TS types `KinetixHealthSnapshot/Check`. i18n `health_*`.
- **Gallery**: `/health` fixture in `http.ts` + `kinetix_health` (poll 0) in inertia stub. Tests: `HealthMetricsTest` (unavailable snapshot without spatie, endpoint 200 available:false authorized + 403 unauthorized via `actingAs`), `KinetixHealthStatus.spec.ts` (composable load + component badge/rows/unavailable). Full guide: `docs/health.md`.

---

## 40. Kinetix Table Repeater (form field — editable table of rows)

Roadmap v0.54.0. `TableRepeater extends Repeater` (`src/Forms/Components/TableRepeater.php`), type `table-repeater`. A repeater rendered as a spreadsheet table (row per item, column per sub-field) with footer summaries + CSV export. **Deferred by default** (rows in form state, saved with the form); **autosave opt-in** via `->relationship()->autosave()`.

- **PHP API**: `columns([...])` (alias of `schema()`), `summarize(['col'=>'sum'|'avg'|'count'|'min'|'max'])`, `exportable()`, `relationship('items')`, `autosave()`, inherits `minItems/maxItems/addActionLabel`. `toData()` adds `summarize`/`exportable`/`autosave` to `FormFieldData`, and when **autosave + relationship + record exists** mints `autosaveToken = Crypt::encrypt(['parent'=>record::class,'key'=>,'relation'=>,'columns'=>allowlist])` (allowlist = schema field names). New `FormFieldData` fields: `autosave`/`autosaveToken`/`summarize`/`exportable`.
- **`TableRepeaterController`** (`src/Forms/`): `store`/`update`/`destroy` decode the token via `resolve()` (validates parent Model + `HasOneOrMany` relation), operate on `$parent->{relation}()`, and `values()` filters payload to the column allowlist (`array_intersect_key`). Routes in `registerTableRoutes()` under `{prefix}/tables/table-repeater` (POST/PUT/DELETE), `kinetix.table-repeater.*`.
- **Vue (published)**: `KinetixTableRepeater` (props `comp`/`modelValue`/`errors`, emits `update:modelValue`). Renders each cell via nested `<KinetixFormSchema>` with **label/description stripped** (`cellColumns`) — reuses all field types; header from `comp.schema[].label`; footer summaries computed client-side; CSV export client-side; add/remove rows. **Autosave**: when `comp.autosave` + `comp.autosaveToken`, add→`create` (sets returned id on row), cell edit→debounced (500ms) `update` per row id, remove→`remove`. `useKinetixTableRepeater` → `{create, update, remove}` (POST/PUT/DELETE to `/tables/table-repeater`). Wired in `KinetixFormSchema` as `comp.type === 'table-repeater'` (circular import OK — same as Wizard). Reuses i18n `add_item`/`remove`/`export`; new `table_repeater_empty`.
- Tests: `TableRepeaterTest` (serialize+summaries, token payload/allowlist, no token without record, autosave create writes only allowlisted cols, update+delete, tampered-token 400 — needs `actingAs`), `KinetixTableRepeater.spec.ts` (headers/rows/empty/add/remove/summary + autosave create; stubs `KinetixFormSchema` + mocks the composable). Full guide: `docs/table-repeater.md`.

---

## 41. Copyable / Revealable inputs + copyable table columns (v0.55.0)

- **TextInput**: `->copyable()` (click-to-copy button) and `->revealable()` (masked/password-style with a reveal toggle — for API keys/secrets); combine for a copyable secret. `FormFieldData.isCopyable`/`isRevealable`. Rendered by **`KinetixCopyableInput.vue`** (relative wrapper: input + `Eye`/`EyeOff` reveal toggle flipping `type` text/password + `Copy`/`Check` button using `navigator.clipboard`, 1.5s copied state). Wired in `KinetixFormSchema` as a more-specific branch **before** the plain text-input (`comp.type==='text-input' && (isCopyable||isRevealable)`).
- **Table columns**: `copyable()` is now on the **base `Column`** (`$isCopyable` + method; `toData` falls back to `$this->isCopyable`), so any column (incl. `TextColumn`) supports it — not just `ColorColumn`. `KinetixTableCell` text-normal cell shows a hover `Copy` button emitting the existing `copy-to-clipboard` (handled by `KinetixTable.copyToClipboard`). Cell gained `useI18n` for `t('kinetix.copy')`.
- i18n: reuse `copy`; new `reveal`/`hide`. Tests: `CopyableTest` (TextInput + TextColumn serialization), `KinetixCopyableInput.spec.ts` (copy via clipboard mock with `Object.defineProperty(navigator,'clipboard',…)`, mask→reveal type flip, update:value, no buttons when neither). Docs: `docs/forms.md` + `docs/tables.md`.

---

## 42. Kinetix Media Library (form field — multi-file manager, optional spatie)

Roadmap v0.56.0. `MediaLibrary extends FileUpload` (`src/Forms/Components/`), type `media-library`. Multi-file grid: drag-drop/click upload (reuses the existing `{prefix}/uploads/store` UploadController + signed uploadToken), thumbnail grid, **drag-reorder** (native HTML5 DnD), delete, preview. Multiple + reorderable by default.

- **PHP API**: `collection(name)`, `conversions([...])`, `reorderable(bool)`, inherits disk/directory/image/maxSize/maxFiles/acceptedFileTypes + uploadToken. `toData` adds `mediaCollection`/`mediaConversions`/`isReorderable` to `FormFieldData`. Value = ordered array of `{id?, path?, url, name, size?, mime?, thumb?}` (new uploads carry `path`, existing spatie media carry `id`).
- **Optional spatie/laravel-medialibrary** (`MediaManager`, singleton, guarded by `interface_exists('Spatie\MediaLibrary\HasMedia')` + `is_a`): `usesSpatie($record)`, `items($record, $collection, $conversion?)` (maps `$record->getMedia()` → item array; `[]` without spatie), `sync($record, $collection, $items, $disk?)` (builds ordered id list: keep items with `id`, `addMediaFromDisk($path,$disk)->toMediaCollection()` for new, delete media not in list, `$mediaClass::setNewOrder($ids)`; **no-op without spatie**). `KinetixMedia::items()/sync()` static facade. Host wires hydrate (`fill(['gallery'=>KinetixMedia::items(...)])`) + persist (`KinetixMedia::sync(...)` in update). phpstan.neon ignores `Model::(getMedia|addMediaFromDisk|media)` for `src/Media/MediaManager.php`.
- **Vue (published)**: `KinetixMediaLibrary` (props value/uploadToken/acceptedFileTypes/isImage/maxFiles/reorderable/disabled, emits update:value). Grid: image thumb (`thumb||url`) or `FileText` icon; upload zone (Loader2 while uploading); reorder via dragstart/dragover/drop (guards file-drop vs reorder via `dragIndex`); remove X; preview via `kinetix:preview` CustomEvent (image) or `window.open` (file). Wired in `KinetixFormSchema` as `comp.type === 'media-library'`. i18n `media_add`/`media_uploading`/`media_upload_failed`; reuse `remove`.
- Tests: `MediaLibraryTest` (serialize incl. inherited token, reorderable toggle, manager no-op + facade without spatie), `KinetixMediaLibrary.spec.ts` (grid img/icon, upload appends via fetch mock + `Object.defineProperty(input,'files')`, remove, DnD reorder). Gallery specimen uses data-URI SVG thumbs (offline). **Deferred**: folders, native (non-spatie) variants. Full guide: `docs/media-library.md`.

---

## 43. Kinetix Scheduled Reports (backend — email an Exporter on a schedule)

Roadmap v0.57.0. Config `reports` (`enabled`). **Backend-only** (no Vue). Reuses the Export system.

- **`ScheduledReport`** value object (`src/Reports/`): `make($key)->exporter(class)->frequency('daily'|'weekly'|'monthly'|…)->to(array|string)->subject()->parameters([])->enabled(bool)`. Getters; `getSubject()` defaults to `str($key)->headline()` (Title Case).
- **`ReportRegistry`** (singleton): `register/get/all/due($frequency?)` (due = enabled + frequency match). **`KinetixReports::register()/all()`** facade — register in a provider boot().
- **`ReportRunner`**: `run($report)` → false if no recipients; else instantiate exporter with params, `generate()` writes temp file via `FileWriter` (headings + `resolveExportQuery()->chunk(mapRecord)` + `summaryRow`), `Mail::to(recipients)->send(ScheduledReportMail)`, unlink in finally. **`ScheduledReportMail`** (Queueable Mailable): Envelope subject + htmlString body (i18n `report_mail_intro`/`report_mail_outro`) + `Attachment::fromPath`.
- **`SendReportsCommand`** `kinetix:reports:send {report?} {--frequency=}`: single report by key (FAILURE if unknown) or `registry->due($frequency)`. Registered in `commands()`. Host schedules per cadence (`Schedule::command('kinetix:reports:send --frequency=daily')->dailyAt(...)`).
- Tests: `ScheduledReportTest` (registry frequency/enabled filter, definition defaults, runner emails with attachment via `Mail::fake`, skip no-recipients, command sends due + fails unknown). Full guide: `docs/reports.md`.

---

## Generators (Artisan)

`kinetix:make-resource` (full CRUD: `--generate`/`--simple`/`--soft-deletes`/`--team`), `kinetix:make-action`, `make-table`, `make-form`, `make-infolist`, `make-importer`, `make-exporter`, `make-relation-manager`, `make-notification`, `kinetix:make-billing` (`--seeder`). All write to `app/Kinetix/{Type}/` (billing → `resources/js/pages/Billing/`) and accept `--force`. Built on a shared `GeneratorCommand` base.

## Testing & Static Analysis

- **PHPUnit + orchestra/testbench** (`vendor/bin/phpunit`, in-memory sqlite) under `tests/` (`Happones\Kinetix\Tests\`). **Larastan/PHPStan level 5** (`vendor/bin/phpstan analyse`, config `phpstan.neon`). **Vitest + @vue/test-utils + happy-dom** for Vue (`npm run test:unit`, specs in `resources/js/components/__tests__/`; i18n components need an i18n plugin via `global.plugins`). Every change must keep all three green.
- **Lint & format (aligned with the Laravel Vue starter kit, v0.39.0)**: `npm run lint`/`lint:check` (ESLint flat config — scoped to `resources/js` + `eslint.config.js`; type-imports, import/order, `@stylistic` padding + 1tbs braces, `curly:all`) and `npm run format`/`format:check` (Prettier with `.prettierrc`: **single quotes, 4-space, printWidth 80, `prettier-plugin-tailwindcss`** sorting `cn`/`clsx`/`cva` against `resources/css/kinetix.css`). Run `format` before `lint --fix` (eslint-config-prettier disables conflicts; a second `format` pass converges any eslint-rewritten files). `.editorconfig` + `.prettierignore` present. **GOTCHA**: ESLint's TS import resolver follows testbench's recursive `vendor/orchestra/testbench-core/laravel/vendor → vendor` symlink → `ELOOP`; that symlink is a PHP-test runtime artifact (gitignored), so JS lint runs cleanly in CI/fresh checkouts. Locally, `rm` it before linting and recreate (testbench remakes it on the next `phpunit`).

