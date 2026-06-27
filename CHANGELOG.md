# Changelog

All notable changes to `kinetix` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> **Published assets.** Kinetix publishes Vue components, stores, TypeScript
> types, translations and config into your app. When upgrading, re-publish with
> `--force` (`php artisan vendor:publish --tag=kinetix-components --force`, etc.)
> and review the entries below marked **(published)** for changes you may have
> customized locally.

## [Unreleased]

## [0.27.0] - 2026-06-26

### Added

- **Connected Accounts / social auth** (`connected_accounts`, optional, requires
  `laravel/socialite`, **published**) — a complete social-auth feature (the
  official Laravel Vue starter kit ships **no** OAuth, so this is a full feature,
  not a complement):
  - **Sign in / register with a provider** (opt-in guest flow): find-or-create
    the user, link the identity, and log in. Customize resolution/creation with
    `KinetixConnectedAccounts::resolveUserUsing()` / `createUserUsing()`.
  - **Link / unlink providers** for an authenticated user via the drop-in
    `KinetixConnectedAccounts` Vue manager (built-in GitHub/Google brand glyphs).
  - **Set a password** for social-only users (no current password required) so
    email + password login also works; existing-password users get a change form.
  - **Lockout protection**: blocks unlinking the last sign-in method when the
    user has no password (`prevent_lockout`).
  - Backed by the `kinetix_connected_accounts` table (tokens **encrypted** at
    rest, never serialized), `ConnectedAccount` model, `ConnectedAccountData`,
    `ConnectedAccountManager`, `ConnectedAccountProviderRegistry`, and the
    `useKinetixConnectedAccounts` composable. The User model needs no trait.
- New docs: **"Connected Accounts"** guide and **"Kinetix & the Laravel starter
  kit"** ownership matrix (updated to mark social auth as Kinetix-owned).
- New i18n keys (en/es/fr/pt): `connected_account_*` and `password_*`.

### Dependencies

- Adds `laravel/socialite` (^5.0) as a **suggested** dependency — required only
  when you enable Connected Accounts.

## [0.26.0] - 2026-06-26

### Added

- **`AddressPicker` form field** (`address-picker`, **published**) — a structured
  address field storing `{ line1, line2, city, state, postalCode, country }`.
  Renders a text input per part plus a **searchable country select** sourced from
  a built-in ISO 3166-1 alpha-2 list (`Support\Countries`). Limit/reorder the
  sub-fields with `->fields([...])`; replace the country options with
  `->countries([...])`. Backed by `AddressData` and a new
  `FormFieldData.addressFields` payload; Vue `KinetixAddressPicker`.
- **`AddressFilter` table filter** (type `address`) — a single text input that
  matches the term with **OR `LIKE`** across the columns passed to
  `->columns([...])` (defaults to the filter name). Pairs with `AddressPicker`.
- New i18n keys (en/es/fr/pt): `address_line1`, `address_line2`, `address_city`,
  `address_state`, `address_postal`, `address_country`, `address_search`.

## [0.25.0] - 2026-06-26

### Added

- **`KinetixSpotlightTrigger`** — a visible header launcher for the Spotlight
  palette (search-box style with a `⌘K` / `Ctrl K` hint, collapses to an icon
  button on small screens). Dispatches a `window` `kinetix:spotlight` event that
  `<KinetixSpotlight>` now listens for, so the two stay decoupled and the
  keyboard shortcut keeps working independently.
- **`WeekPicker` / `WeekFilter` `->startWeek(0-6)`** — region-aware first day of
  the week (default Monday), wired to the calendar's `weekStartsOn`.

### Changed

- **`WeekPicker`** now highlights the **entire selected week** (range-style)
  instead of only the clicked day.
- **`TimePicker`** now renders as an **input-style trigger that opens a popover**
  (matching the date pickers) and **defaults to a 12-hour clock with AM/PM**;
  call `->twentyFourHour()` for 24-hour.

### Fixed

- **Docs**: two Mermaid diagrams (forms, infolists) used parentheses/`&` in
  `subgraph` titles, which Mermaid v11 rejects — they rendered a "syntax error"
  box. Quoted the titles (`subgraph backend ["Backend (Laravel)"]`).

## [0.24.1] - 2026-06-26

### Fixed

- **Feature flags / guests**: feature resolvers run on every Inertia response,
  including for unauthenticated users (the scope is `null`). A user-scoped
  resolver like `fn ($user) => $user->isBetaTester()` would dereference `null`
  and 500 the page for guests. `FeatureManager` now resolves a throwing
  resolver as **inactive** when the scope is `null` (guest), and `all()` resolves
  per-flag so one bad resolver can't break the whole set. Errors for
  authenticated scopes still surface.

## [0.24.0] - 2026-06-26

### Added

- **Accessibility** (optional): per-user accessibility preferences + screen-reader
  primitives — inclusion as a first-class feature.
  - Preferences: **reduce motion**, **increase contrast**, **text size**
    (normal/large/x-large), **underline links**, **enhanced focus**. Persisted
    per user (`kinetix_accessibility` table), shared on every Inertia response,
    and applied to `<html>` **before the app mounts** (no flash) by the new
    `KinetixAccessibility` Vue plugin (`app.use(...)`), with a localStorage mirror.
  - **`<KinetixAccessibilityPanel />`** (**published**) for an account/settings
    page; `useKinetixAccessibility` for a custom UI. Self-service endpoints
    `GET/POST {prefix}/accessibility`.
  - Screen-reader primitives: **`<KinetixSkipLink />`** (skip-to-content) and
    **`useKinetixAnnounce()`** (shared ARIA live region for announcing async
    updates). Enabled via `KINETIX_ACCESSIBILITY_ENABLED`; i18n `a11y_*` /
    `skip_to_content` (en/es/fr/pt).

## [0.23.0] - 2026-06-26

### Added

- **`DateRangePicker` form field** (start + end date, stores `{from,to}`).
  Renders the shadcn range calendar in a popover by default, or two native date
  inputs via `->native()`; supports `->numberOfMonths()`, `->weekdayFormat()`,
  `->fixedWeeks()`, `->locale()`, `->minValue()/->maxValue()`. New
  `KinetixDateRangePicker` component (**published**). Completes the range pair
  with the existing `DateRangeFilter` table filter. Adds `numberOfMonths` /
  `weekdayFormat` / `fixedWeeks` to `FormFieldData` (via `Field::rangeConfig()`).
  i18n `pick_date_range` (en/es/fr/pt).

## [0.22.0] - 2026-06-26

### Added

- **Month / Year / Week pickers** — as both **form fields** and **table filters**:
  - Fields `MonthPicker` (`Y-m`), `YearPicker` (`Y`), `WeekPicker` (`o-\WW`).
    Shadcn popover by default (month grid + year nav / paginated year grid /
    calendar that picks the clicked day's ISO week), or the browser-native input
    via `->native()` (`<input type="month|number|week">`). New `KinetixMonthPicker`,
    `KinetixYearPicker`, `KinetixWeekPicker` components (**published**).
  - Filters `MonthFilter` (whereYear+whereMonth), `YearFilter` (whereYear),
    `WeekFilter` (ISO-week date range), rendered with the same pickers.
  - **Native bounds**: generic `->minValue()` / `->maxValue()` on the `Field`
    base (mapped to native `min`/`max`) — also now available on `DatePicker` /
    `DateTimePicker`. i18n `pick_month` / `pick_year` / `pick_week` / `week_of`.

### Fixed

- Time pickers (`TimePicker` / `DateTimePicker`) scroll the selected hour/minute
  into view on open instead of starting at the top.

## [0.21.0] - 2026-06-26

### Added

- **`TimePicker` form field** (time-only). Renders the shadcn scrollable
  hour/minute (+ AM/PM in `->twelveHour()`) columns by default and stores an
  `H:i` string; `->native()` falls back to `<input type="time">`, `->minuteStep()`
  sets granularity. New `KinetixTimePicker` component (**published**).

### Docs

- Embedded the missing component screenshots into the feature docs: all form
  **layouts** (Grid, Fieldset, Tabs, Split, Placeholder), the new **TimePicker**,
  and a proper **DateTimePicker** capture (popover opened, showing the calendar +
  time columns — previously only a blank time area was visible). Added resource
  page captures (page header + table). The screenshot tool can now open a
  teleported popover before capturing (`openSelector`).

## [0.20.1] - 2026-06-26

### Added

- **`Action::iconButton()`** — render an action as a compact icon-only button
  (no visible label, no outline; the shadcn row-action style). The label is kept
  for `aria-label`/tooltip. Serialized as `ActionData.isIconButton`.

### Changed

- **`KinetixActionDropdown` trigger** now matches shadcn: with no group label it
  renders a borderless ghost **⋮** icon button (the row-action look); a labelled
  group still gets an outlined, labelled trigger. The outline + label are now
  opt-in rather than always-on.

## [0.20.0] - 2026-06-26

### Added

- **Reorderable tables**: `Table::reorderable('sort_order')` adds drag-and-drop
  row reordering with a grip-handle column. The new order is persisted to the
  given integer column via a signed, token-guarded `tables/reorder` endpoint
  (the reorder column is baked into the same encrypted model token as inline
  cell edits, so it can't be forged); rows default to that order.

### Changed

- **Table polling now works**: `Table::poll('10s')` was serialized but never
  wired on the frontend. `KinetixTable` now drives it through Inertia's
  **`usePoll`** (partial reload, preserves scroll + table state). Inertia
  `usePoll` is the standard polling approach for the package (notifications
  remain real-time via Echo).
- **Tables aligned to the shadcn data-table look**: rows carry
  `data-state="selected"` styling, and the markup/density matches shadcn's
  table (header, hover, selection, grip handle, status badges).

### Fixed

- Screenshot light/dark toggle: the generic image rule outranked the dark-hide
  toggle, so the dark capture leaked into light mode (both showing).

### Docs

- Component screenshots are now **embedded in each feature's documentation**
  (forms, tables, billing, widgets, permissions, membership, etc.) instead of a
  single previews page (removed). A new light/dark-aware `<Screenshot>` doc
  component swaps to the dark capture when the docs site is in dark mode. The
  gallery now covers **34** components (incl. the reorderable table).

## [0.19.1] - 2026-06-26

### Fixed

- **Impersonation banner**: the "Return to your account" button inherited the
  banner's white text, making the label invisible on the banner's background.
  It now uses `text-foreground` explicitly. (Surfaced by the new screenshot
  tooling.)

### Tooling / Docs

- **Automated component screenshots.** A Vite gallery (`gallery/`) renders each
  component with mock props + real translations + shadcn tokens, and a Playwright
  script (`scripts/screenshots.mjs`, `npm run screenshots`) captures light/dark
  2× PNGs into `docs/public/screenshots/` for embedding in the docs. **33
  components** are now wired (all major UI surfaces), shown on the new
  [Component previews](docs/component-previews.md) page. Dev-only — nothing
  ships to consumers.

## [0.19.0] - 2026-06-26

### Added

- **GDPR self-service** (optional): two privacy actions for users.
  - **Download my data**: register sections with
    `KinetixGdpr::export('profile', fn ($user) => …)`; a queued `GdprExportJob`
    builds a JSON dump of all sections, stores it on the Kinetix disk, and
    notifies the user with a download link (reusing the exports download route).
  - **Delete my account**: `POST {prefix}/gdpr/delete` validates the current
    password (when `kinetix.gdpr.require_password`), then **anonymizes** the
    configured PII columns or **hard-deletes** the record (`kinetix.gdpr.deletion`),
    and logs the user out. Override entirely with `KinetixGdpr::deleteUsing(...)`.
  - **`<KinetixGdprPanel />`** (**published**) renders both actions (delete behind
    a password-gated confirmation dialog); `useKinetixGdpr` for a custom UI.
    Enabled via `KINETIX_GDPR_ENABLED`. i18n `gdpr_*` (en/es/fr/pt).

## [0.18.0] - 2026-06-26

### Added

- **Table summaries** — render aggregate footer rows and include them in exports.
  - `Column::summarize(Summarizer|Summarizer[])` adds summarizers rendered in a
    `<tfoot>` over the **full filtered dataset**. Ships `Sum`, `Average`,
    `Count` (row count, scope with `->query()`), and `Range` (`min – max`, with
    `minimalTextualDifference()`/`minimalDateTimeDifference()`/`excludeNull()`/
    `limit()`), plus `Summarizer::make()->using(fn ($q) => …)` for custom values.
  - Shared formatting: `label()`, `query()` (scoped dataset), `prefix()/suffix()`,
    `numeric(decimalPlaces, locale)`, `money(currency, divideBy, locale)`,
    `hidden()/visible()`. Global locale via `config('kinetix.tables.number_locale')`.
  - Serialized as `TableData.summaries` / `hasSummaries` and `ColumnData.hasSummary`
    (**published** — `KinetixTable` footer). i18n `summary_total` (en/es/fr/pt).
  - **Export parity**: `ExportColumn::summarize(...)` appends a totals row to the
    CSV/XLSX (computed over the exported query, so bulk-selected exports total
    exactly those rows). `Exporter::hasSummary()`; suppress with
    `protected bool $withSummary = false`.

## [0.17.0] - 2026-06-26

### Added

- **Wizard** — multi-step flows in two surfaces sharing one Vue core:
  - **Form layout**: `Wizard::make()->variant(...)->steps([Step::make(...)])`.
    Advancing is gated on the current step's **required** fields (server
    validation still runs on submit). Adds `Wizard`/`Step` components and
    `variant`/`slug`/`isRequired` to `FormFieldData`.
  - **Standalone `<KinetixWizard>`** (**published**): drop into any page; step
    content via slots, `beforeNext` guard for per-step validation, events
    `update:step`/`step-change`/`finish`, and five shadcn indicator variants
    (`default`, `simple`, `vertical`, `panels`, `gradient`). `useKinetixWizard`
    composable; new `KinetixFormTabs`-style wrapper `KinetixFormWizard`.
  - **Route gating**: `kinetix.wizard:<slug>` middleware redirects to the
    configured route until the user completes the wizard; completion is
    persisted per user (or per team) in a new `kinetix_wizard_completions`
    table. Config `wizards` (`enabled`/`teams`/`gates`); publish
    `--tag=kinetix-wizards-migrations`. Endpoints `GET/POST {prefix}/wizards/{slug}[/complete]`.
    i18n `wizard_*` (en/es/fr/pt).

## [0.16.0] - 2026-06-26

### Added

- **Form layout components** — rounding out Filament-style layouts alongside the
  existing `Grid` and `Section`:
  - **`Fieldset`** — a labelled, bordered `<fieldset>`/`<legend>` group with
    `columns()` and nesting.
  - **`Tabs`** + **`Tab`** — a Reka UI tab strip; each `Tab` has its own schema
    and an optional `icon()`. Fields in every tab are still validated and saved.
  - **`Split`** — a responsive flex row (side-by-side from `md`, stacked below).
  - **`Placeholder`** — a read-only `label` + `content` display block, excluded
    from validation and dehydration.
  - All nest arbitrarily and share `columnSpan()` / `visible()` / `hidden()` /
    `visibleOn()` / `hiddenOn()`. Nested fields anywhere in a layout are
    auto-discovered for validation/hydration. (**published** — Vue renderer +
    new `KinetixFormTabs` component.) Adds `icon` / `content` to `FormFieldData`.

### Note

- The **Wizard** layout and the standalone `<KinetixWizard>` page component (with
  variants and a completion-gating middleware) are planned for v0.17.0.

## [0.15.0] - 2026-06-26

### Added

- **Onboarding** (optional): a first-run experience with three composable pieces.
  - **Checklist** (backend-driven): declare steps with
    `KinetixOnboarding::step('key', 'Title')->cta(...)->completedUsing(...)`. Steps
    with a `completedUsing` callback auto-complete from app state; the rest are
    manual ("Mark as done") and persist per user (or per team) in a new
    `kinetix_onboarding` table. `<KinetixOnboardingChecklist />` (**published**)
    renders progress + CTAs + dismiss; `useKinetixOnboarding` for a custom UI.
    Self-service routes: `GET/POST {prefix}/onboarding[/complete|/dismiss]`.
    Enabled via `KINETIX_ONBOARDING_ENABLED`; publish
    `--tag=kinetix-onboarding-migrations`.
  - **`<KinetixEmptyState />`** (**published**): reusable "no data yet" block
    (icon + title + description + CTA slot).
  - **`<KinetixTour />`** + `useKinetixTour` (**published**): dependency-free
    product tour that spotlights elements by CSS selector and auto-starts once
    per id (localStorage). i18n `onboarding_*` / `tour_*` (en/es/fr/pt).

## [0.14.0] - 2026-06-26

### Added

- **Developer Tokens** (optional, requires `laravel/sanctum`): a self-service
  dashboard for users to mint, scope and revoke personal access tokens.
  - `KinetixTokens::scopes(['posts.read' => 'Read posts', ...])` declares the
    grantable Sanctum abilities (also configurable via `kinetix.tokens.scopes`).
    When a catalog is declared, tokens must be granted ≥1 declared scope;
    otherwise tokens default to full access (`*`).
  - `<KinetixTokenManager />` (**published**) lists tokens, creates them with a
    scope picker, reveals the plaintext token **once**, and revokes. Composable
    `useKinetixTokens`, type `KinetixToken`, i18n `token_*` (en/es/fr/pt).
  - Self-service routes (no admin ability): `GET/POST {prefix}/tokens`,
    `DELETE {prefix}/tokens/{token}`. Enabled via `KINETIX_TOKENS_ENABLED`. The
    User model must use `Laravel\Sanctum\HasApiTokens`. Enforce abilities on your
    API with standard Sanctum (`auth:sanctum` + `ability:`).

## [0.13.0] - 2026-06-26

### Added

- **`Action::shortcut('c')`** binds a keyboard shortcut to an action; when the
  action is rendered in a `KinetixPageHeader`, Kinetix registers the hotkey
  automatically (running the action, confirmation modal included). Serialized as
  `ActionData.shortcut`.

### Fixed

- **Code style**: re-aligned the `=>` operator in the translation files
  (`resources/lang/*/kinetix.php`) that drifted as keys were added, so
  `pint --test` is green again.

### Changed

- **Documentation**: the home page feature cards are regrouped into six major
  areas (Resources/Tables/Forms · Infolists & Actions · Import-Export & Relations
  · Authorization & Identity · SaaS Platform · Search & Experience) so the grid
  stays readable as modules grow.
- `kinetix-development` skill: Pint must be run over the **whole repo**
  (`vendor/bin/pint`), not just `--dirty` — `--dirty` misses files (e.g. lang `=>`
  alignment) made non-compliant by earlier commits, which then fail CI's
  `pint --test`.

## [0.12.0] - 2026-06-26

### Added

- **Keyboard Shortcuts module** (frontend-only). App-wide hotkeys, conflict-safe
  by design:
  - Single keys (`c`/`e`/`d`/`/`) and Gmail-style sequences (`g i`) fire only when
    not typing; `mod+…` combos (⌘/Ctrl) still fire while typing; `?` opens help.
    Browser/OS-reserved `Ctrl+`-combos are deliberately avoided, and
    `preventDefault` only runs on a match.
  - **(published)** `v-kinetix-hotkey` directive (`@/plugins/kinetixHotkeys`) —
    bind a key to any element (clicks it or runs a handler), so any component
    (Kinetix or not) can react; the `useKinetixHotkeys` composable; a
    `<KinetixShortcuts>` `?` cheat-sheet overlay; and per-user binding overrides
    (`setHotkeyOverrides`) persistable via the Settings module.
  - Native matcher (no extra dependency); `@vueuse/core` documented as a drop-in
    alternative.
- `kinetix-keyboard-shortcuts` Boost skill and a section (§19) in the development
  skill.

## [0.11.0] - 2026-06-26

### Added

- **Searchable `Select` (combobox).** `Select::make(...)->searchable()` renders the
  field as a Reka UI **Combobox with a search box** (the new published
  `KinetixCombobox`) instead of a plain dropdown:
  - **Local**: with `options()`, the search filters them client-side.
  - **Remote**: `->searchUsing($model, $labelColumn, $searchColumns, $valueColumn)`
    queries the server as you type — **debounced (250ms) and lazy** (fetched only
    when opened) — so large datasets aren't shipped to the client. The model +
    columns are encrypted into a signed token (the query can't name an arbitrary
    table/column — same guard as inline table edits), hitting the new
    `POST {prefix}/forms/search` endpoint; the selected option's label is resolved
    server-side so it shows immediately.
- **(published)** `KinetixCombobox` component; `FormFieldData` gains
  `isSearchable` + `searchToken`; `KinetixFormSchema` renders the combobox for
  searchable selects (plain `<KinetixSelect>` otherwise — no change for existing
  selects).

## [0.10.1] - 2026-06-26

### Added

- **Webhooks now prefer `spatie/laravel-webhook-server`** when installed (its
  tuned retries/backoff), via a new `webhooks.driver` config (`auto` by default;
  falls back to the native queued job; force with `spatie` / `native`).
  `spatie/laravel-webhook-server` added to `suggest`.
  - The dispatcher routes through spatie's `WebhookCall` (re-checking SSRF, since
    spatie delivers directly) and tags each call with `meta` so a listener on
    spatie's `WebhookCallSucceeded`/`WebhookCallFailed` events records the same
    `WebhookLog` entries — the dashboard stays consistent across drivers.
  - Driver differences documented: with spatie, the signature uses spatie's
    `Signature` header and retries/timeout come from spatie's own config.

## [0.10.0] - 2026-06-26

### Added

- **Webhooks module** (optional — `KINETIX_WEBHOOKS_ENABLED`, off by default).
  Let customers hook platform events into their own services — roadmap stage 6:
  - Declare subscribable events with `KinetixWebhooks::events([...])` and fire them
    with `KinetixWebhooks::fire($event, $payload)`; each fire queues a **signed**
    (`X-Kinetix-Signature` = HMAC-SHA256), **retried** and **logged** delivery to
    every active, subscribed endpoint in the team scope.
  - **SSRF protection** (`WebhookUrlGuard`): customer URLs are validated at save
    time and before each delivery — non-HTTP(S) and private/loopback/link-local/
    reserved IPs (incl. cloud metadata) are rejected unless `allow_private` is on.
  - Customer dashboard endpoints (gated `webhooks.manage`): CRUD, rotate secret
    (shown once), send test, delivery logs, redeliver. Plus the
    `kinetix:webhooks:prune` retention command and the `kinetix-webhooks-migrations`
    publish tag.
- **(published)** `<KinetixWebhookManager>` (register/edit endpoints with event
  checkboxes, rotate, test, inspect logs), the `useKinetixWebhooks` composable,
  `KinetixWebhookEndpoint` / `KinetixWebhookLog` types and `webhook_*` translations.
- `kinetix-webhooks` Boost skill and a section (§18) in the development skill.
- Docs: a "Testing your webhooks" tip pointing at webhookcatcher.com.

## [0.9.0] - 2026-06-26

### Added

- **Spotlight Command Palette module** (optional — `KINETIX_SPOTLIGHT_ENABLED`,
  off by default). A global `Cmd/Ctrl+K` search over models, navigation and
  actions — roadmap stage 5:
  - Register sources with `KinetixSpotlight::register([...])`:
    `SpotlightResource` (a searchable model — title/subtitle/url/icon/group, with
    `searchColumns` for the LIKE fallback and an optional scoping `query()`) and
    `SpotlightLink` (a nav link `url` or a client `event`, with `keywords`).
  - **Authorization-aware**: source-level `->authorize($ability)` plus per-record
    `view` policy filtering — results never leak records a user can't see. Empty
    queries don't touch the database.
  - **Driver** `auto` routes a `Searchable` model's search through `laravel/scout`,
    else a capped `LIKE` query (`database`). `laravel/scout` added to `suggest`.
  - Endpoint `GET {prefix}/spotlight?q=…` returns grouped results.
- **(published)** `<KinetixSpotlight>` — the palette, built on Reka UI's `Dialog`
  + `Combobox` (focus trap, keyboard nav, selection), owning the `Cmd/Ctrl+K`
  shortcut; selecting navigates or dispatches a client event. Plus the
  `useKinetixSpotlight` composable, `KinetixSpotlightItem` / `KinetixSpotlightGroup`
  types and `spotlight_*` translations.
- `kinetix-spotlight` Boost skill and a section (§17) in the development skill.

## [0.8.0] - 2026-06-26

### Added

- **Feature Flags module** (optional — `KINETIX_FEATURES_ENABLED`, off by
  default). Gradual rollout and plan-gating — roadmap stage 4:
  - `KinetixFeatures` facade — `define($name, Closure|bool)`, `active()`,
    `inactive()`, `all()`. A flag's resolver receives the scope (user, or team
    when `features.teams` is on) and can defer to anything, including Billing's
    `$user->canUseFeature(...)` for plan-gating.
  - **Driver** `auto` (default) resolves through `laravel/pennant` when installed
    (persistence, lottery, scopes), otherwise a native closure evaluator — force
    with `pennant` / `native`. `laravel/pennant` added to `suggest`.
  - `kinetix.feature:<flag>` route middleware (404s when inactive).
- **(published)** `<KinetixFeature flag="…">` gate component (with a `#denied`
  slot, mirroring `<KinetixCan>`), the `useKinetixFeature` composable, and the
  `kinetix_features` shared prop (resolved flag map). No new translations.
- `kinetix-feature-flags` Boost skill and a section (§16) in the development skill.

## [0.7.0] - 2026-06-26

### Added

- **Impersonation module** (optional — `KINETIX_IMPERSONATION_ENABLED`, off by
  default). Admin "log in as user", done safely — roadmap stage 3:
  - `ImpersonationManager` / `KinetixImpersonation` facade (`start` / `stop` /
    `isImpersonating`), with the original user kept in the session and the target
    resolved through the auth provider (no User model reference).
  - **Safety**: the `users.impersonate` ability governs who may impersonate
    (auto-registers with the permission matrix); a built-in escalation guard
    blocks impersonating a super-admin unless you are one (override via the
    `can_impersonate` closure); the `kinetix.impersonation.protect` middleware
    403s sensitive routes (password/email/2FA/billing/deletion) while
    impersonating; and start/stop are **audited** through the Activity event spine.
  - Endpoints `POST {prefix}/impersonate/{user}` and `DELETE {prefix}/impersonate`.
- **(published)** `<KinetixImpersonationBanner>` (a "you are impersonating … —
  return to your account" bar, shown only when active), the
  `useKinetixImpersonation` composable, `ImpersonateAction` (a prebuilt user-row
  action), the `KinetixImpersonationState` type and `impersonate*` translations.
- `kinetix-impersonation` Boost skill and a section (§15) in the development skill.

## [0.6.1] - 2026-06-26

### Added

- **Activity Log now prefers `spatie/laravel-activitylog`** — the de-facto
  standard — when it's installed, instead of reimplementing audit logging. A new
  `activity.driver` config (`auto` by default) detects spatie and logs through it;
  otherwise it falls back to the native `kinetix_activity` table (so the feature
  still works with zero extra dependencies). Force either with `spatie` / `native`.
  - Both drivers normalize to the **same `ActivityData` DTO** — `<KinetixActivityLog>`
    and the endpoint are unchanged.
  - Team-scoping with the spatie driver is carried in `properties.team_id` (no
    change to spatie's schema); `kinetix:activity:prune` delegates to spatie's
    `activitylog:clean`.
  - `spatie/laravel-activitylog` added to `suggest`.

### Changed

- `ActivityLogger::log()` / `KinetixActivity::log()` now return `?Model` (was the
  native `Activity`) and `ActivityLogged` carries a base `Model`, so the event
  spine and facade are storage-agnostic across drivers. Existing native-only
  setups are unaffected.

## [0.6.0] - 2026-06-26

### Added

- **Activity Log module** (optional — `KINETIX_ACTIVITY_ENABLED`, off by default).
  A native, team-scoped audit trail and the event spine later modules consume —
  roadmap stage 2:
  - `LogsKinetixActivity` model trait auto-records `created` / `updated` /
    `deleted` (causer = `auth()->user()`, updates capture an old→new diff); the
    `KinetixActivity` facade logs anything; every entry dispatches the
    `ActivityLogged` event.
  - Team-scoped store (`kinetix_activity`), gated by the `activity.view` ability
    (auto-registers with the permission matrix); a paginated `GET {prefix}/activity`
    feed (filter by `subject_type` / `subject_id` / `event`); the
    `kinetix:activity:prune` retention command; and the `kinetix-activity-migrations`
    publish tag.
- **(published)** `<KinetixActivityLog>` — a self-loading, paginated ("load more")
  timeline that works **globally or scoped to one record** (per feature, e.g. a
  product's change history on its show page). Descriptions are composed from i18n
  (`activity_event_*`, `activity_by`, `activity_system`) so "Created by …" /
  "Actualizado por …" translate (en/es/fr/pt). Plus the `useKinetixActivity`
  composable and `KinetixActivityEntry` / `KinetixActivityResponse` types.
- `kinetix-activity` Boost skill and a section (§14) in the development skill.

## [0.5.1] - 2026-06-26

### Added

- **(published)** Application settings can now be dropped into the host's **own
  settings layout as a tab** — the same way the starter kit hosts Roles &
  Permissions. `<KinetixSettingsForm page-key="general">` self-loads its page DTO
  (no host controller needed), mirroring `<KinetixRoleManager>`; the settings
  `index`/`show` endpoints now content-negotiate JSON, and `useKinetixSettings`
  gains `load()` + a `loading` flag (with a pulsing skeleton while it fetches).
- **Documentation**: a "Account settings vs. application settings" section
  clarifying the scope (admin app-config vs the starter kit's per-user account
  settings) and a copy-paste recipe for adding it as a settings tab.

## [0.5.0] - 2026-06-26

### Added

- **Settings module** (optional — `KINETIX_SETTINGS_ENABLED`, off by default). A
  database-backed, class-based settings panel built on the Forms engine — the
  first SaaS roadmap module:
  - `SettingsPage::schema()` defines fields with Kinetix Form components; each is
    persisted under `{group}.{field}`.
  - `KinetixSettings` facade — `get()` / `set()` / `forget()` / `all()` /
    `pages()`. Team-scoped (null team = global), cached with write-invalidation,
    type-preserving (JSON), with an `encrypted` option for secrets.
  - Gated by the `settings.manage` ability (auto-registers with the permission
    matrix when Permissions is enabled); team-aware endpoints; the
    `kinetix-settings-migrations` publish tag; and a `kinetix:make-settings-page`
    generator.
- **(published)** `KinetixSettingsForm` component (reuses `<KinetixForm>`),
  `useKinetixSettings` composable, the `KinetixSettingsPageData` type and a
  `settings_saved` translation (en/es/fr/pt).
- `ROADMAP.md` — the versioned SaaS-features roadmap (Settings → Audit + event
  spine → Impersonation → Feature Flags → Spotlight → Webhooks, plus add-ons).
- `kinetix-settings` Boost skill and a Settings section (§13) in the development
  skill.

### Fixed

- **(published)** Cleared the last shadcn-vue **v3** focus rings: the
  `<KinetixForm>` default submit button now uses `buttonVariants()` (it was a
  hand-rolled class with `focus-visible:ring-1`), and the table sort-header button
  uses the v4 `focus-visible:ring-[3px]` ring.

## [0.4.8] - 2026-06-26

### Fixed

- **(published)** Aligned the last hand-rolled controls to shadcn-vue
  **new-york-v4**: the table search box, the filter-panel inputs (number/date
  range) and the inline-edit cell input dropped the v3 focus ring
  (`focus:outline-none focus:ring-1 focus:ring-ring`) for the v4 set
  (`outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]`);
  `KinetixTagsInput`'s wrapper moved from `shadow-sm focus-within:ring-1` to
  `shadow-xs` + the v4 `focus-within:*` ring. The Reka primitives (Select,
  Checkbox, Switch, Toaster/Sonner, Label) and the variant helpers were already
  on v4 — this clears the remaining drift.

## [0.4.7] - 2026-06-26

### Added

- **Documentation**: Completed the doc-vs-code audit by documenting every
  remaining real-but-undocumented capability (each verified against source):
  - **Tables**: `poll()`, `paginated()` / `defaultPaginationPageOption()`,
    `recordUrl()`, `Filter::label()`, the Table-as-a-class subclass pattern
    (`buildColumns()`/… + `render()`), and the known search/sort dot-notation
    limitations (single-level `whereHas`; dotted sorts skipped).
  - **Forms**: `extraAttributes()` / `extraFieldWrapperAttributes()`, conditional
    `required(Closure)`, `Form::operation()` / `model()`, `Form::render()`, and the
    `buildSchema()` subclass pattern.
  - **Actions**: corrected the default-color guidance (View/Edit = `gray`,
    Create = `primary`, Delete/ForceDelete = `danger`), `markAsUnread()`,
    `ExportAction`/`ImportAction` in the prebuilt reference, and the team-aware URL
    resolution in `Action::toData()`.
  - **Notifications**: builder helpers `body()` / `status()` / `seconds()` /
    `persistent()` / `icon()` / `iconColor()`, and the `kinetix.notifications.broadcast`
    config key.
  - **Infolists**: `Infolist::record()` / `columns()` / `operation()`,
    `ImageEntry::disk()` + path-to-URL resolution, and `Section::actions()`.
  - **Resources**: the `registerPermissions()` override, the
    `kinetix:make-relation-manager` generator, and the navigation metadata getters
    / auto-derived label.
  - **Widgets**: the shared `Widget` base API (`id`/`title`/`description`/`columnSpan`/`sort`)
    and the `Stat::descriptionColor()` default (`gray`).
  - **Billing**: the remaining `BillingManager` methods (`billable()`,
    `ensureStripeCustomer()`, `setupIntent()`, `defaultPaymentMethodId()`,
    `addPaymentMethod()`, `removePaymentMethod()`, `downloadInvoice()`).

## [0.4.6] - 2026-06-26

### Fixed

- **Documentation**: Removed a fabricated `formatStateUsing()` form-field method
  from the Forms guide (it only exists on table columns / infolist entries).
  Documented the real reactive hooks instead — `afterStateUpdated()` + `live()`.
- **Documentation**: Corrected the teams env var in the installation guide —
  `KINETIX_TEAMS` → `KINETIX_TEAMS_ENABLED` (the variable the config actually reads).
- **Documentation**: `vue-i18n:generate` comes from the optional
  `happones/laravel-vue-i18n-generator` package; the installation and home pages
  now tell you to install it (or use your own vue-i18n toolchain) instead of
  presenting it as a built-in Kinetix command.
- **Documentation**: The Membership state diagram no longer shows `Expired` as a
  stored status — it's derived from `expires_at` via `isExpired()` (status stays
  `pending` / `active` / `revoked`).
- **Documentation**: The Widgets sparkline example referenced a non-existent
  `stat.id`; it now uses the `v-for` index for the gradient id.
- **Documentation**: The Infolists guide no longer claims infolists have "no
  inline actions" — a `Section` can carry header actions via `Section::actions()`.

### Added

- **Documentation**: The Resources guide now documents how to build a read-only
  **View / Show page** (the `infolist()` hook + a `show()` controller method +
  `ViewAction` + relation managers scoped to the `view` page), cross-linked to the
  Infolists "Show page" recipe — previously the capability shipped but wasn't
  documented end-to-end.

## [0.4.5] - 2026-06-26

### Changed

- **Documentation**: Completed the Kinetix Resources documentation by detailing route registration (including soft deletes and team prefix), the hook methods on the Resource class (infolist, relationManagers, and permissionFeature), and the Create/Edit Vue views.
- **Documentation**: Added the Roles, Permissions & Membership card to the Vitepress home page features section in the index page.

## [0.4.4] - 2026-06-24

### Added

- **(published)** `KinetixLabel` — the shadcn-vue new-york-v4 `Label` (built on
  Reka UI's `Label`), so form field labels stop being hand-styled `<label>`s.
  Adopted in `KinetixFormSchema`, `KinetixRoleForm` and the membership components.

### Fixed

- **(published)** Membership provisioning form alignment: the "Add member" button
  used `size: 'sm'` (`h-8`) next to `h-9` inputs/select, so with `items-end` it
  sat ~4px low. It now uses the default (`h-9`) button — every control is `h-9`,
  so labels, inputs, the role select and the button line up. Label↔input spacing
  normalized to `space-y-2`.

### Changed

- `kinetix-development` skill: field labels must use `<KinetixLabel>` (not raw
  `<label>`), and a note to align rows by matching the `h-9` control height
  (default button, `items-end`) rather than nudging with margins.

## [0.4.3] - 2026-06-23

### Fixed

- **(published)** The Membership components now use the Reka UI / shadcn-vue
  new-york-v4 primitives instead of raw HTML: `KinetixMemberProvisioner` and
  `KinetixMemberList` render the role picker via `<KinetixSelect>`, inputs via
  `inputClass`, and the status badge via `statusBadgeClass`; `KinetixMemberActivation`
  uses `inputClass`. No hand-rolled field/select markup remains — aligns the
  0.4.0 components with the rest of the toolkit.

### Added

- `kinetix-development` skill rule: every control and style must trace back to
  Reka UI + shadcn-vue new-york-v4 — use the Kinetix Reka primitives
  (`KinetixSelect`/`KinetixCheckbox`/…) and the canonical class helpers
  (`inputClass`/`buttonVariants`/`badgeVariants`/`useStatusColor`), never native
  controls or invented styles.

## [0.4.2] - 2026-06-23

### Changed

- Corrected the `HasTeams` × spatie `HasRoles` `teams()` collision guidance for
  `spatie/laravel-permission` **v8** (Laravel 13): v8 ships `teams()` as a
  convenience relation it never calls internally (scoping uses
  `getPermissionsTeamId()`), so `insteadof` alone resolves the collision — the
  alias is optional, not required. Permissions guide (§4), the `kinetix-permissions`
  skill and the `kinetix-development` skill updated, with a table contrasting what
  each `teams()` returns.

## [0.4.1] - 2026-06-23

### Added

- `kinetix-membership` Laravel Boost skill, and a Membership section in the
  `kinetix-development` skill — documenting the admin-provisioned onboarding
  module shipped in 0.4.0.
- `kinetix-development` skill rule requiring a consumer-facing Boost skill
  (`resources/boost/skills/kinetix-<feature>/SKILL.md`) for every new feature.
- Documented the `HasTeams` × spatie `HasRoles` `teams()` trait collision and its
  fix (PHP trait conflict resolution — `insteadof` + `as` alias) in the
  permissions guide (§4) and the `kinetix-permissions` skill.

## [0.4.0] - 2026-06-23

### Added

- **Membership & Provisioning** (optional — `KINETIX_MEMBERSHIP_ENABLED`). An
  admin-provisioned onboarding model as an alternative to the starter-kit's
  self-serve team invitations: an admin adds an email + role, the person
  activates by setting a password via a single-use, expiring signed link. No
  personal team is created and the role is a dynamic Kinetix role drawn from a
  curated `assignable_roles` allow-list — so a provisioner can never escalate
  someone to `admin`. The allow-list is enforced at provision **and** activation
  time.
- Registers a `members` feature (`members.viewAny` / `provision` / `update` /
  `revoke`) with the permission registry, so it appears in the permission matrix
  and `kinetix:permissions:sync`. Management endpoints are team-aware and gated
  through Laravel's Gate like `roles.manage`; activation endpoints are public but
  protected by a temporary signed URL.
- Kinetix never touches the host's team pivot — optional `attach_member` /
  `detach_member` config callables let the host (de)attach the activated user to
  its own team membership.
- **(published)** Vue components `KinetixMemberList`, `KinetixMemberProvisioner`
  and `KinetixMemberActivation`, the `useKinetixMembers` composable, the
  `KinetixMemberProvision` TypeScript type and `member_*` / `activation_*`
  translations (en/es/fr/pt).
- Publishable `kinetix_member_provisions` migration
  (`--tag=kinetix-membership-migrations`).
- Documentation: new **Membership & Provisioning** guide.

## [0.3.2] - 2026-06-23

### Fixed

- Inline table edits (`cell-update`) now send the `XSRF-TOKEN` cookie like the rest
  of Kinetix, instead of relying on a `<meta name="csrf-token">` tag that Inertia
  apps usually don't render (which could fail with a 419).

### Changed

- All stateful `fetch` calls consolidated into a single `useKinetixHttp` helper
  (`kinetixFetch` + `xsrfToken`), removing duplicated XSRF/header logic across the
  actions, roles, notifications store, file upload, importer and table components.

## [0.3.1] - 2026-06-23

### Fixed

- `php artisan kinetix:install` now installs the front-end runtime dependencies the
  published components import (`reka-ui`, `@internationalized/date`, `@lucide/vue`,
  `vue-sonner` — plus `pinia`/`vue-i18n`), so a fresh install no longer fails with a
  Vite *"Failed to resolve import …"* error. Optional deps via `--charts`
  (`@unovis/*`) and `--broadcasting` (`@laravel/echo-vue`); all documented.

### Changed

- Frontend test specs moved out of `resources/js/**/__tests__` into `tests/js/`, so
  `vendor:publish` no longer copies `*.spec.ts` / test helpers into your app.

## [0.3.0] - 2026-06-23

### Added

- **Roles & Permissions** (optional — `spatie/laravel-permission`). Enforcement
  flows through Laravel's Gate (Kinetix actions already use it); this adds authoring,
  management and frontend gating:
  - **Registry**: feature-scoped `KinetixPermissions::feature()/resource()` with
    `{feature}.{ability}` keys; hybrid source (auto-CRUD from Resources via
    `permissionFeature()` + explicit features); `kinetix:permissions:sync` (`--prune`).
  - **Backend**: `super-admin` Gate bypass; a `kinetix.permissions.team` middleware
    bridging spatie's team id to the starter-kit `currentTeam`; the
    `kinetix_permissions` Inertia prop (resolved permissions/roles — no host
    middleware editing); role-management endpoints (`{prefix}/permissions/...`, gated
    by `roles.manage`); `RoleData` / `PermissionFeatureData` DTOs; `KinetixRolesSeeder`
    (super-admin/admin/editor/viewer preset). Config under `kinetix.permissions`.
  - **Frontend (published)**: `useKinetixCan` composable, `<KinetixCan>` gate, `v-can`
    directive (via the `KinetixPermissions` plugin), and a drop-in `KinetixRoleManager`
    / `KinetixRoleForm` / `KinetixPermissionMatrix` (feature-grouped, search,
    select-all). i18n in en/es/fr/pt.

### Fixed

- `php artisan kinetix:install` no longer injects the TypeScript cast
  (`as string | undefined`) into a JavaScript entry file (`app.js`), which was a
  syntax error; the cast is now emitted only for `.ts` entries. Added a test suite
  for the installer (TS/JS injection, store creation, idempotency).

## [0.2.1] - 2026-06-23

### Fixed

- Hydration mismatch issues by wrapping Vue `<Teleport>` body targets with an `isMounted` guard inside `KinetixConfirmModal` and `KinetixNotificationDrawer`.
- Configured generated `createI18n` calls from `php artisan kinetix:install` to use `legacy: false` by default, resolving Vue i18n warnings in the console.

## [0.2.0] - 2026-06-23

### Added

- Automated installer command `php artisan kinetix:install` to initialize frontend dependencies (Pinia, Vue i18n).
- Setup guide details for integrating Vue i18n dynamically using Inertia's `withApp` option in the main entry file (`app.ts` / `app.js`).

## [0.1.1] - 2026-06-22

### Added

- GitHub repository CI/CD workflow configurations for automated PHP/JS testing and VitePress site deployment.
- Standardized Issue and Pull Request templates for the open-source repository.
- Comprehensive submodules development reference guidelines inside `.agents/skills/kinetix-development/SKILL.md`.
- Integration of the official VitePress Mermaid plugin (`vitepress-plugin-mermaid`) for markdown diagrams.
- Dedicated VitePress theme logos for light and dark modes (`logo.png`/`logo_w.png`) and custom favicon (`icon.png`).
- Standardized MIT License file for open-source compliance.

## [0.1.0] - 2026-06-22

### Added

- Custom UI primitives (`Dialog`, `Popover`, `Select`, `ScrollArea`) styled with shadcn v4 tokens.
- Complete subscription and billing management system with Stripe integration, billing manager commands, plans, and customized billing components.
- Advanced Date, Time, and DateTime picker components (`KinetixDatePicker`, `KinetixDateTimePicker`, `KinetixCalendar`).
- Download, Export, Import, and Preview pre-built action wrappers with support for file previewing and background HTTP requests.
- Documentation site (VitePress) under `docs/`, deployed to GitHub Pages via
  `.github/workflows/docs.yml`; reuses the existing feature docs. Run locally with
  `npm run docs:dev`.
- Relation managers can be limited to specific pages via `protected static array
  $visibleOn = ['edit', 'view']` (+ overridable `isVisibleOn($page)`); use
  Resource::relationManagersFor($page) to get the page's managers.
- Laravel Pint integration (`pint.json`, `composer format`).
- `KinetixToaster` (published) — vue-sonner Toaster themed with shadcn tokens so
  toasts read correctly in dark mode.
- Open-source project scaffolding: `LICENSE`, `CONTRIBUTING`, `CODE_OF_CONDUCT`,
  `SECURITY`, this changelog and CI.

### Fixed

- **Bulk export now exports only the selected rows.** `Exporter::resolveExportQuery()`
  auto-scopes the export to the selected `ids` (`whereKey`) — previously a bulk
  `ExportAction` exported the whole table unless you manually scoped `query()` by
  `parameter('ids')`.
- Notification single delete/read failed when `kinetix.teams` was enabled — route
  closures now resolve `{id}` by name rather than positionally.
- Background notification requests now send `Accept: application/json` +
  `credentials: same-origin` so auth/CSRF failures aren't silently followed.
- Component styling (published) aligned to shadcn-vue **new-york-v4**: Switch,
  Checkbox, RadioGroup, Select and the table toggle cell; the focus ring token is
  now `ring-[3px]` everywhere (was the no-op `ring-3`).
- Table pagination now serializes `from` / `to`, so the "Showing X to Y of Z"
  line renders correctly (was `undefined`).
- Type safety: `vue-tsc --noEmit` is now clean; hand-maintained TS types gained
  `KinetixTableData.model`, pagination `from`/`to`, `KinetixTableColumn.isBadge`,
  `KinetixTableRecord.actions`, and a `KinetixConfig` / `KinetixSharedProps` for
  typing `usePage()`.
- Memory safety: the table search debounce is cleared on unmount, and the
  notifications Echo subscription is stopped on unmount.
