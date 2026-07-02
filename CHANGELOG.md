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

## [0.68.5] - 2026-07-01

### Fixed

- **Kinetix Config (`config/kinetix.php`)** — Changed the `billing.plan_model` default class reference to a string class path representation (`'Happones\Kinetix\Billing\Plan'`), matching the styling of `billable` and avoiding class loading/import resolution errors in host applications where the package is not yet fully bootstrapped. **(published)**

## [0.68.4] - 2026-07-01

### Fixed

- **Chart Widget (`KinetixChartWidget`)** — Added optional chaining and null-safety guards to data accessors (`xAccessor`, `yAccessors`, `pieValueAccessor`, `pieLabelAccessor`), and added a fallback rendering state using `<KinetixEmptyState>` when no data/labels are present, avoiding runtime TypeError crashes in Unovis components. **(published)**

## [0.68.3] - 2026-07-01

### Fixed

- **Pricing Table component (`KinetixPricingTable`)** — Changed the absolute alias import of `KinetixPlanCard` to a relative import, correcting compilation issues in host applications where published files live under a subdirectory. **(published)**

## [0.68.2] - 2026-07-01

### Fixed

- **Stripe JS Composable (`useKinetixStripe`)** — Changed dynamic import using Vite-ignored comments to a direct dynamic import to prevent resolution errors at runtime.
- **Kinetix Event Calendar Tests** — Fixed flaky unit tests in `KinetixEventCalendar.spec.ts` by pinning the system time using fake timers in the test setup.

### Refactored

- **Billing Page Layout (`Billing/Index.vue`)** — Realigned the template output in `MakeBillingCommand` to place pricing tables and payment method/invoices sections in the specified grid layout. **(published)**
- **Extract Secure Payments Card** — Refactored the "Secure Payments" section to a new reusable component `KinetixSecurePayments.vue`, loading translation keys (`billing_secure_payments` / `billing_secure_payments_desc`) dynamically from translated files. **(published)**

## [0.68.0] - 2026-06-27

### Added

- **`AddressPicker::except()`** — hide one or more address sub-fields without
  spelling out the full allowlist, e.g. `->except('country')` or
  `->except(['line2', 'country'])`. The ergonomic inverse of `->fields()`;
  composes after it and preserves the remaining order. **(published)**

## [0.67.0] - 2026-06-27

### Added

- **`ProgressWidget`** — a goal/quota panel rendering a value against a target as
  a horizontal bar (default) or a circular ring with the percentage in the
  center. Fluent API: `value()`, `target()`, `display()`, `caption()`,
  `color()`, `ring()`. The percentage is computed from `value/target` and clamped
  to 0–100 (a zero target yields 0% without dividing). Registered in
  `KinetixWidgetsGrid` under `type: 'progress'`. **(published)**

## [0.66.0] - 2026-06-27

### Changed

- **Chart area fills now use a gradient** (solid → transparent per series),
  matching the shadcn-vue chart look (previously a flat translucent fill). Lines
  stay solid; tooltips unchanged.
- **`Carbon` typehints widened to `CarbonInterface`** so callers can pass either
  `Carbon` or `CarbonImmutable`: `KinetixAnnouncements::publish()` /
  `AnnouncementManager::create()` `$publishedAt`, and `AnnouncementManager::seenAt()`.
- **Notification bell trigger** now uses the shared `buttonVariants` (ghost
  icon-sm) with the icon in `currentColor` — consistent with the dark-mode/locale
  header buttons (was a hand-rolled button with a `muted-foreground` icon).



### Added

- **Mail Templates module** (`mail_templates`, optional, **published**) —
  editable email templates (subject + Markdown/HTML body with `{{ variable }}`
  placeholders) stored in `kinetix_mail_templates` and managed from the
  `<KinetixMailTemplates>` UI (list, editor, **live preview**, send-test). Your
  app supplies the variable data and triggers sends via
  `KinetixMail::send($to, $key, $data)` (also `render()` and `test()`).
  `MailTemplate::render()` interpolates variables (HTML-escaped in Markdown
  bodies) and compiles Markdown; `TemplatedMail` (queueable) delivers it.
  Self-service CRUD + preview/test endpoints gated by the `viewKinetixMail`
  ability (default allow-`local`). i18n `mail_*` (en/es/fr/pt); new
  `KinetixMailTemplate`/`KinetixMailVariable` TS types; migration tag
  `kinetix-mail-templates-migrations`.

### Fixed

- vue-i18n compilation crashes: removed literal `{{ … }}` and `@` from
  translation strings (`editor_tiptap_missing`, mail hints/placeholders) — `@`
  and `{{` are reserved by vue-i18n (linked messages / nested placeholders) and
  would throw "Invalid linked format" / "Not allowed nest placeholder" when the
  string was rendered.

## [0.64.0] - 2026-06-27

### Added

- **Hero / CTA widget** (`HeroWidget`, **published**) — a prominent panel with a
  greeting + headline value, a delta line and a primary button (e.g.
  "Congratulations Toby! · $15,231.89 · +65% from last month · View Sales").
  `HeroWidget::make()->title()->subtitle()->value()->delta($text, $color)
  ->action($label, $url)->gradient()`. Registered as `type: hero`.
- **Chart header metrics** (**published**) — `ChartWidget::metric($label, $value,
  $badge?, $badgeColor?)` (chainable) shows headline figures in the chart header
  (e.g. DESKTOP 24,828 / MOBILE 25,010, or a value + trend chip). New
  `KinetixChartMetric` TS type; `KinetixWidget.type` includes `hero`.



### Added

- **Rating widget** (`RatingWidget`, **published**) — a ratings summary panel:
  an average score with proportional stars (half-stars via a clipped overlay) and
  a per-level breakdown of review counts as colored bars (green→red), like a
  product "Customer Reviews" card. `RatingWidget::make()->average(4.5)
  ->total(5500)->breakdown([5 => 4000, 4 => 2100, …])`; the breakdown is emitted
  high→low with computed percentages. Registered as `type: rating` in
  `KinetixWidgetsGrid`; supports header actions. i18n `rating_out_of`/
  `rating_reviews` (en/es/fr/pt); new `KinetixRatingLevel` TS type. Final step of
  the widget-enrichment series.

## [0.62.0] - 2026-06-27

### Added

- **Period filter** (**published**) — the common dashboard date-range control
  (Last 7 days / 30 days / 3 months / This month / …), end to end:
  - **`KinetixPeriodFilter.vue`** — segmented buttons or a select dropdown,
    `v-model` the period key.
  - **`useKinetixPeriod(initial?, { navigate, only })`** composable — `period`
    (seeded from `?period=`), `range` (`{ start, end }` ISO, client-side), and
    `setPeriod()` which can push `?period=` to the server (Inertia visit).
  - **`Period` PHP parser** (`Support\Period`) — `range($key)`,
    `fromRequest($request, $default)`, `scope($query, $column, $key)` apply the
    matching `[start, end]` (CarbonImmutable) bounds to a query. The same key set
    (`today`/`yesterday`/`7d`/`30d`/`90d`/`month`/`year`/`all` + `custom`) drives
    component, composable and parser so it agrees client↔server.
  - i18n `period_*` (en/es/fr/pt). (Third step of the widget-enrichment series.)

## [0.61.0] - 2026-06-27

### Added

- **Stat card badge + footer link** (**published**): `Stat::make()->badge('+6.1%',
  'success')` shows a small trend chip in the card header; `->url('View more',
  '/path')` adds a footer link. (Second step of the widget-enrichment series.)
- **Widget header actions** (**published**): `Widget::headerAction($label, $url,
  $icon?)` (chainable) adds link/button actions to a widget header — e.g.
  "Export", "View all". Rendered by a shared `WidgetHeaderActions` component in
  the Chart, Table and List widget headers. New `KinetixWidgetAction` TS type;
  `KinetixWidget` gains `headerActions`; `KinetixStat` gains `badge`/`badgeColor`/
  `linkLabel`/`linkUrl`.

> Custom slots for arbitrary content (hero/CTA, segmented controls) are already
> supported via `CustomWidget` + the per-widget named slot in `KinetixWidgetsGrid`.

## [0.60.0] - 2026-06-27

### Added

- **More chart variants** (`ChartWidget`, **published**):
  - **`chartType('horizontalBar')`** — a crisp div-based horizontal bar chart
    (category label + bar + value), ideal for "by category" / ranking panels.
  - **Stacked area** — `area` charts now render a stacked `VisArea` (array `y`)
    with per-series outline lines.
  - **Stacked bars** — `->stacked()` renders `VisStackedBar` for `bar` charts.
  - **Legend** — `->legend()` shows a labelled color-swatch legend below the
    chart (dataset labels for XY, category labels for donut/horizontal).
  - **Donut center label** — `->centerLabel($value, $caption)` overlays a big
    value + caption in the middle of a `pie`/`doughnut` (e.g. "10.2K Visitors").

First step of the widget-enrichment series (stat actions, widget header
actions/slots, period filter, rating widget follow).

## [0.59.0] - 2026-06-27

### Added

- **Richer dashboard widgets** (**published**):
  - **Stat cards** gain `Stat::make()->icon('dollar-sign')->iconColor('info')` —
    a leading icon in a colored badge (shown in place of the sparkline), matching
    the common KPI-card style. Pairs with the existing trend
    `description`/`descriptionIcon`/`descriptionColor`.
  - **New `ListWidget`** (`type: list`) — a list/feed panel for recent activity,
    stock alerts, latest orders, etc. `ListWidget::make()->items([...])->icon()
    ->action($label, $url)->emptyState()`; each `ListItem::make($title)
    ->subtitle()->icon($name, $color)->value()->badge($text, $color)
    ->progress(0–100)->url()` renders a leading icon badge, title + subtitle, a
    trailing value/badge and an optional progress bar; an optional footer link.
  - **Chart `area` type** — `chartType('area')` renders a filled line.
  - `resolveIcon()` gained common dashboard icons (dollar-sign, shopping-cart,
    shopping-bag, users, package, clock, activity, alert-triangle, wallet, …).
- New `KinetixListItem` TS type; `KinetixStat` gains `icon`/`iconColor`;
  `KinetixWidget.type` includes `list`.

## [0.58.0] - 2026-06-27

### Added

- **Failed-job retry/delete in the Queue widget** (**published**) — the queue
  health widget now lists recent failed jobs (name + queue) with **Retry** and
  **Delete** actions. `QueueMetrics::failed()` reads Laravel's failed-job store
  (works with or without Horizon, parsing the job's display name from the
  payload); `retry($id)` re-queues via `queue:retry`, `forget($id)` deletes via
  the failer. New gated endpoints `POST {prefix}/queue/retry` +
  `DELETE {prefix}/queue/failed`. `useKinetixQueue()` gains `retry`/`forget`; the
  snapshot gains a `failed` array. i18n `queue_retry` (en/es/fr/pt). Closes the
  last roadmap gap (Tier 5).

## [0.57.0] - 2026-06-27

### Added

- **Scheduled Reports module** (`reports`, optional) — email an Exporter's output
  on a schedule. Register reports with `KinetixReports::register(ScheduledReport
  ::make('daily-orders')->exporter(OrdersExporter::class)->frequency('daily')
  ->to([...])->subject()->parameters([...]))`, then run `kinetix:reports:send`
  from the scheduler (filter with `--frequency=daily|weekly|monthly`, or run one
  by key). `ReportRunner` builds the export file via the shared `FileWriter`
  pipeline (CSV/XLSX/PDF) and mails it as an attachment (`ScheduledReportMail`,
  queueable). `ReportRegistry` (singleton) + `KinetixReports` facade; reports with
  no recipients are skipped. i18n `report_mail_intro`/`report_mail_outro`
  (en/es/fr/pt). Backend-only (no published Vue).

## [0.56.0] - 2026-06-27

### Added

- **Media Library field** (**published**) — a multi-file media manager:
  drag-drop / click to upload, a thumbnail grid, **drag-to-reorder**, delete and
  preview. `MediaLibrary::make()->collection()->conversions()->image()` builds on
  `FileUpload` (same signed upload token / disk / constraints; multiple +
  reorderable by default). The value is an ordered array of media items
  (`{ id?, path?, url, name, size?, mime?, thumb? }`). **Optional
  spatie/laravel-medialibrary support**: when installed and the record is
  `HasMedia`, `KinetixMedia::items($record, $collection, $conversion?)` hydrates
  the field and `KinetixMedia::sync($record, $collection, $state, $disk?)`
  reconciles the collection on save (adds new uploads, removes deleted, persists
  order) — a no-op without spatie, so the same form code works either way.
  `KinetixMediaLibrary.vue` component; `MediaManager` (guarded, string-class
  spatie detection); new `FormFieldData` `mediaCollection`/`mediaConversions`/
  `isReorderable`. i18n `media_add`/`media_uploading`/`media_upload_failed`
  (en/es/fr/pt). Reuses the existing `{prefix}/uploads/store` endpoint.

## [0.55.0] - 2026-06-27

### Added

- **Copyable & revealable inputs** (**published**) — `TextInput::make()->copyable()`
  adds a click-to-copy button; `->revealable()` masks the value (password-style)
  with a reveal toggle, ideal for API keys/tokens/secrets (combine both for a
  copyable secret field). Rendered by the new `KinetixCopyableInput.vue`.
- **Copyable table columns** (**published**) — `Column::copyable()` (on the base
  column, so any column type incl. `TextColumn`) shows a hover click-to-copy
  button on the cell that copies its value via the existing table clipboard
  handler.
- i18n `reveal`/`hide` (en/es/fr/pt; `copy` already existed). New `FormFieldData`
  `isCopyable`/`isRevealable`.

## [0.54.0] - 2026-06-27

### Added

- **Table Repeater form field** (**published**) — a `Repeater` rendered as an
  editable, spreadsheet-style table (one row per item, one column per sub-field),
  with footer **summaries** (`sum`/`avg`/`count`/`min`/`max`), **CSV export**,
  and live add/edit/delete of rows. Reuses `KinetixFormSchema` (label-stripped)
  per cell, so every field type works as a column. Two save modes: **deferred**
  (default — rows in form state, saved with the parent form) and **autosave**
  (`->relationship('items')->autosave()` persists each change immediately via a
  signed-descriptor endpoint that only writes the declared columns on the bound
  relation). `TableRepeater::make()->columns()->summarize()->exportable()`;
  `useKinetixTableRepeater()` composable; `KinetixTableRepeater.vue`; new
  `kinetix.table-repeater.*` routes. i18n `table_repeater_empty` (en/es/fr/pt).

## [0.53.0] - 2026-06-27

### Added

- **System health widget** (`health`, optional, **published**) — a lightweight,
  embeddable application-health widget powered by spatie/laravel-health, in the
  same vein as the Queue widget. `HealthMetrics` reads the latest stored check
  results (guarded string-class resolution, so it works without the package
  installed) and derives a worst-of overall status. The gated
  `GET {prefix}/health` endpoint (ability `viewKinetixHealth`, default
  allow-in-`local`) returns a snapshot; `<KinetixHealthStatus />` renders an
  overall status badge + a per-check list (status icon + summary), polling on the
  configured interval. `useKinetixHealth()` composable; `kinetix_health` Inertia
  share. i18n `health_*` (en/es/fr/pt). New `KinetixHealthSnapshot`/
  `KinetixHealthCheck` TS types.

## [0.52.0] - 2026-06-27

### Added

- **Queue health widget** (`queue`, optional, **published**) — a lightweight,
  embeddable queue-metrics widget that complements (does not replace) the Horizon
  dashboard. `QueueMetrics` reads Laravel Horizon's repositories when installed
  (throughput, recent jobs, per-queue wait, supervisor status) and falls back to
  queue sizes + the `failed_jobs` table otherwise, so it works on any driver. The
  gated `GET {prefix}/queue` endpoint (ability `viewKinetixQueue`, default
  allow-in-`local`) returns a live snapshot; `<KinetixQueueStats />` renders a
  status badge + throughput/recent/pending/failed tiles + a per-queue list,
  polling on the configured interval. `useKinetixQueue()` composable;
  `kinetix_queue` Inertia share (enabled + poll). Config: `queues` (monitored
  without Horizon), `poll`. i18n `queue_*` (en/es/fr/pt). New
  `KinetixQueueSnapshot`/`KinetixQueueRow`/`KinetixQueueConfig` TS types.

## [0.51.0] - 2026-06-27

### Added

- **Presence / Online indicators module** (`presence`, optional, **published**) —
  show who's online in real time over a Reverb/Pusher presence channel. Kinetix
  registers the channel authorization (team-aware; returns each member's
  `id`/`name`/`avatar`) so you add nothing to `routes/channels.php`, and shares
  the team-resolved channel name via the `kinetix_presence` Inertia prop.
  `<KinetixOnlineUsers :max="5" />` renders a live avatar facepile (image or
  initials, "+N" overflow, "{n} online" count); `useKinetixPresence()` exposes
  `{ users, count, isOnline, channel }` for custom UIs (e.g. a green online dot),
  tracking Echo's `here`/`joining`/`leaving` and leaving on unmount. Config:
  `channel`, `name_attribute`, `avatar_attribute`. Requires broadcasting
  (`kinetix:install --broadcasting`). i18n `presence_online` (en/es/fr/pt). New
  `KinetixPresenceUser`/`KinetixPresenceState` TS types.

## [0.50.0] - 2026-06-27

### Added

- **Resource breadcrumbs** (**published**) — Kinetix now auto-derives the
  breadcrumb trail from a Resource instead of you hand-writing it per page (the
  starter kit's `<Breadcrumbs>` component is reused, not replaced).
  `Resource::breadcrumbs($operation, $record?)` returns `{ title, href }[]` for
  `index`/`create`/`edit`/`show`, built from `getNavigationLabel()`,
  `getRecordTitle()` (defaults to `name`→`title`→`#id`, override via
  `$recordTitleAttribute`) and `getRouteBaseName()` (defaults to the plural-kebab
  model name, override via `$routeBaseName`). Links resolve with `route()`,
  auto-filling the record + a `current_team` param when present, falling back to
  the current URL so they never throw. The `kinetix:make-resource` generator now
  emits `'breadcrumbs' => …Resource::breadcrumbs(…)` from each controller action
  and a typed `breadcrumbs?: KinetixBreadcrumb[]` prop on the generated pages.
  i18n `breadcrumb_create`/`breadcrumb_edit` (en/es/fr/pt). New `KinetixBreadcrumb`
  TS type.

## [0.49.0] - 2026-06-27

### Added

- **Team Switcher module** (`team_switcher`, optional, **published**) — a header
  dropdown to switch the active team. The official starter kit has no teams, so
  this is a complete feature, but Kinetix does **not** own your `Team` model: it
  resolves the user's teams *by convention* (`teams_relation`/`current_relation`/
  `name_attribute`) and shares them — each with a ready-made switch URL built from
  your `switch_route` — via the `kinetix_teams` Inertia prop. The component just
  visits that URL, so it works with whatever switch route your app already has
  (e.g. a controller calling `$user->switchTeam()`). Optional `create_route`
  surfaces a "New team" entry. `<KinetixTeamSwitcher />` + `useKinetixTeams()`
  (`{ teams, current, createUrl, switchTeam }`); i18n `teams_switch/select/new`
  (en/es/fr/pt). Degrades gracefully (`url: null`) when the route is missing.

## [0.48.0] - 2026-06-27

### Added

- **Language Switcher module** (`locale`, optional, **published**) — a
  self-service language switcher. List supported locales (`code => native label`)
  in config and drop `<KinetixLanguageSwitcher />` (icon-only or `show-label`) in
  your header. Selecting a locale flips the SPA instantly via vue-i18n and
  persists the choice in the session (and, with the optional
  `kinetix-locale-migrations` migration, on the user's `locale` column so it
  follows them across devices). The `kinetix.locale` middleware applies the
  persisted locale with `App::setLocale()` on every request when added to the web
  group. The switch endpoint (`POST {prefix}/locale`) is auth-optional, so the
  switcher works on the login screen too. Locales + the active one are shared via
  the `kinetix_locale` Inertia prop. `KinetixLocale::set()/current()/options()`
  static API; `useKinetixLocale()` composable; i18n `language` (en/es/fr/pt).

## [0.47.0] - 2026-06-27

### Added

- **Wizard `fullWidth` option** (**published**) — the horizontal step indicator
  now exposes a `fullWidth` toggle (default `true`). When `true` it stretches to
  fill the container and distributes steps evenly (existing behaviour); set it to
  `false` for a compact, content-sized indicator that centers itself. Available
  on the standalone `<KinetixWizard :full-width="false">`, on the form layout via
  `Wizard::make()->fullWidth(false)`, and applied to the `stepper`, `default`,
  `gradient` and `panels` indicators. Vertical layouts are unaffected.

## [0.46.0] - 2026-06-27

### Added

- **Announcements module** (`announcements`, optional, **published**) — a
  "what's new" feed with a per-user unread badge:
  - Publish entries with `KinetixAnnouncements::publish($title, $body, $level)`
    (`info` / `feature` / `fix`; optional scheduled `published_at`).
  - The `KinetixAnnouncements` header trigger shows an unread count (entries
    published since the user last opened the feed) and a popover listing them;
    opening it marks the feed seen. Backed by the `kinetix_announcements` /
    `kinetix_announcement_views` tables, `AnnouncementManager`, `AnnouncementData`
    and the `useKinetixAnnouncements` composable.
- New i18n keys (en/es/fr/pt): `announcements_title`, `announcements_empty`,
  `announcements_new`.

## [0.45.0] - 2026-06-27

### Added

- **PDF exports** — an `Exporter` can now return `'pdf'` from `format()` to
  produce a printable PDF (a landscape-A4 table of the exported rows, the first
  row as the header) instead of CSV/XLSX. PDF rendering uses the **optional**
  `dompdf/dompdf` package (install only if you export to PDF); CSV/XLSX still
  need nothing extra. Missing dompdf fails fast with an install hint.

### Dependencies

- Adds `dompdf/dompdf` (^3.0) as a **suggested** dependency — required only for
  PDF exports.

## [0.44.0] - 2026-06-27

### Added

- **Calendar module** — a month-view event scheduler over any Eloquent model.
  Define it with the server-driven `Calendar` builder (`->dateColumn()`,
  `->endColumn()` for multi-day spans, `->title()`, `->color()`, `->url()`,
  `->query()`, `->heading()`) and render
  `<KinetixEventCalendar :calendar="calendar.toData()" />`.
  - Events lay out on a 6-week month grid (colored chips, links when a `url` is
    set, "+N more" beyond three per day); months navigate client-side. Emits
    `event-click` / `day-click`. `week-starts-on` + `locale` props. No calendar
    dependency — built on plain `Date` + `Intl.DateTimeFormat`.
  - Backed by `CalendarData` / `CalendarEventData`. No migration, route or config.
  - The Vue component is `KinetixEventCalendar` (distinct from the date-picker's
    `KinetixCalendar`).
- New i18n keys (en/es/fr/pt): `calendar_today`, `calendar_prev`,
  `calendar_next`, `calendar_more`.

## [0.43.0] - 2026-06-27

### Added

- **Kanban module** — a drag-and-drop board over any Eloquent model. Define it
  with the server-driven `Kanban` builder (`->statusColumn()`, `->statuses()`
  with labels + colors, `->cardTitle()`, `->cardDescription()`, `->query()`,
  `->heading()`) and render `<KinetixKanban :kanban="board.toData()" />`.
  - Cards group into columns by a status attribute; dragging a card to another
    column persists the new status (optimistic, reverting on error) via native
    HTML5 drag-and-drop — no extra dependency.
  - The `POST {prefix}/tables/kanban-move` endpoint is guarded by a signed
    descriptor (same mechanism as editable table cells): only the declared status
    column + statuses are writable. No migration or config flag needed.
  - Backed by `KanbanData` / `KanbanColumnData` / `KanbanCardData`.
- New i18n keys (en/es/fr/pt): `kanban_empty`, `kanban_move_failed`.

## [0.42.0] - 2026-06-27

### Added

- **Saved Views module** (`saved_views`, optional, **published**) — per-user
  table presets. Call `->saveViews()` on a `Table` to add a **Views** dropdown to
  its toolbar; users save the current search + filters + sort + page size +
  visible columns under a name, switch between presets, and star a default that
  loads automatically.
  - Views are per-user and team-scoped automatically when `kinetix.teams` is on.
  - Backed by the `kinetix_saved_views` table, `SavedView`, `SavedViewData`,
    `SavedViewManager`, `SavedViewController`, the `KinetixSavedViews` Vue control
    (wired into `KinetixTable`) and the `useKinetixSavedViews` composable.
  - `TableData` gains `savedViewsKey`.
- New i18n keys (en/es/fr/pt): `saved_view*`.

## [0.41.0] - 2026-06-26

### Added

- **Notification Preferences module** (`notification_preferences`, optional,
  **published**) — a per-user opt-in matrix of notification **types × channels**
  (email / in-app / push):
  - Declare channels + types in config or with
    `KinetixNotificationPreferences::types([...])`.
  - Gate a Laravel notification's `via()` with
    `KinetixNotificationPreferences::channelsFor($user, $type, $channels)` (or
    `allows($user, $type, $channel)`). Defaults to enabled; only opt-outs are
    stored, so new types/channels stay on until turned off.
  - `KinetixNotificationPreferences` Vue matrix component +
    `useKinetixNotificationPreferences` composable; backed by the
    `kinetix_notification_preferences` table, `NotificationPreferenceManager` and
    `NotificationTypeRegistry`.
- New i18n keys (en/es/fr/pt): `notification_prefs_*`.

## [0.40.0] - 2026-06-26

### Added

- **Tags module** (`tags`, optional, **published**) — polymorphic, reusable tags
  on any model (real tags stored in their own table, vs the form `TagsInput`
  which only stores a string array on the record):
  - Add the `HasKinetixTags` trait to taggable models and allowlist them with
    `KinetixTags::for([Post::class, ...])`.
  - Tags autocomplete from the existing set, are deduped by slug, and are
    **team-scoped automatically** when `kinetix.teams` is on; a host
    `view`/`update` policy on the model is honored.
  - `TagFilter` filters a table by tag (`whereHas`); the `KinetixTags` Vue
    component (chips + autocomplete + create-on-Enter) + `useKinetixTags`
    composable; backed by `Tag`, `TagManager`, `TagRegistry` and the
    `kinetix_tags` / `kinetix_taggables` tables.
- New i18n keys (en/es/fr/pt): `tag_placeholder`, `tag_remove`.

## [0.39.0] - 2026-06-26

### Changed

- **Lint & format aligned with the official Laravel Vue starter kit.** Added
  `.prettierrc` (single quotes, 4-space, printWidth 80, **`prettier-plugin-tailwindcss`**
  sorting `cn`/`clsx`/`cva`), `.prettierignore` and `.editorconfig`; the whole
  `resources/js` tree was reformatted to match. The ESLint flat config already
  matched the starter kit; `lint`/`format` npm scripts are now scoped to the
  lintable source (`resources/js`, `gallery`, `tests/js`).
- Cleaned up the lint errors this surfaced (unused imports/vars; a template
  type-cast union that tripped `vue/no-deprecated-filter`). The full
  `resources/js` source now passes `eslint` and `prettier --check`.

### Notes

- Consumers on the starter kit can now run their own `npm run format` / `lint`
  over the published Kinetix components without churn.

### Added

- **Comments module** (`comments`, optional, **published**) — polymorphic,
  threaded comments on any model:
  - Declare commentable models with `KinetixComments::for([Post::class, ...])`
    (allowlisted — unregistered types are rejected).
  - Anyone who may **view** a record can read and post; replies thread one level
    deep; each user **edits/deletes only their own** (a host `view` policy is
    honored). Deleting a top-level comment removes its replies.
  - Backed by the `kinetix_comments` table, `Comment` model, `CommentData`,
    `CommentManager`, `CommentRegistry`, the `useKinetixComments` composable and
    the `KinetixComments` Vue component (composer + threaded list with avatars,
    relative times, inline reply/edit/delete).
- New i18n keys (en/es/fr/pt): `comment_*`.

## [0.37.0] - 2026-06-26

### Added

- **`KinetixModeToggle`** — a drop-in dark-mode header button (Sun/Moon icon)
  with a Light / Dark / System dropdown, backed by the new `useKinetixAppearance`
  composable. It mirrors the official Laravel Vue starter kit's Appearance
  contract (same `appearance` localStorage key + cookie, toggles `html.dark`,
  `system` via `prefers-color-scheme`), so it stays in sync with no extra wiring.
- **`KinetixAccessibilityMenu`** — a compact accessibility quick-menu (icon
  button → popover with the same controls as `KinetixAccessibilityPanel`), for
  use outside settings: the header, the login page, the account-setup wizard.

### Changed

- `useKinetixAccessibility().set()` now persists to the server **best-effort**
  (wrapped in try/catch), so the accessibility menu works for **guests** — the
  preference still applies and is mirrored to `localStorage`.

## [0.36.0] - 2026-06-26

### Added

- **`PhoneInput` form field** (`phone-input`, **published**) — an international
  phone field with a searchable country selector (flag + dial code) and a
  national-number input, storing the full E.164-style string (e.g.
  `+5215512345678`). `->defaultCountry('MX')` and `->countries([...])` to
  restrict the list.
- **`Support\DialCodes`** — an ISO 3166-1 → ITU-T E.164 calling-code map
  (`DialCodes::all()` / `DialCodes::for($code)`), paired with `Support\Countries`.
- Backed by `FormFieldData.phoneConfig` and the `KinetixPhoneInput` component
  (flag emoji rendered from regional-indicator code points).

## [0.35.0] - 2026-06-26

### Added

- **Two more form fields** (**published**):
  - **`SlugInput`** (`slug-input`) — a URL-slug text input that generates the
    slug live from a sibling field via `->from('title')` (with `->separator()`),
    until the user edits it manually.
  - **`SignaturePad`** (`signature-pad`) — a canvas signature field (mouse /
    touch / pen) storing a PNG data URL, with `->penColor()`,
    `->backgroundColor()`, `->height()` and a Clear button.
- Backed by `FormFieldData.slugConfig` / `signatureConfig`, the
  `KinetixSlugInput` / `KinetixSignaturePad` components, and a new
  `signature_clear` i18n key (en/es/fr/pt).

## [0.34.0] - 2026-06-26

### Added

- **Three new form fields** (all **published**):
  - **`Slider`** (`slider`) — a single-value range slider (Reka UI) with
    `->min()` / `->max()` / `->step()`; shows the current value beside the track.
  - **`Rating`** (`rating`) — a star rating storing `0..max` with `->max()` and
    `->allowHalf()` (click the current value again to clear).
  - **`PinInput`** (`pin-input`) — a segmented PIN / OTP input (Reka UI) with
    `->length()`, `->mask()`, `->otp()` (one-time-code autofill) and
    `->numeric()`; stores the joined string.
- Backed by `FormFieldData.ratingConfig` / `pinConfig` (Slider reuses
  `numberConfig`) and the `KinetixSlider` / `KinetixRating` / `KinetixPinInput`
  Vue components.

## [0.33.0] - 2026-06-26

### Added

- **`NumberField` form field** (`number-field`, **published**) — a numeric input
  with increment/decrement stepper buttons (Reka UI NumberField), `min` / `max`
  / `step` bounds and `Intl.NumberFormat` formatting via `->percent()`,
  `->currency('USD')`, `->decimals(min, max?)` and `->numberLocale()`.
- **`NumberInputColumn`** (`number-input`) — the inline-editable table twin of
  `NumberField` (same builder methods); edits save through the cell-update
  endpoint.
- Backed by `FormFieldData.numberConfig` / `ColumnData.numberConfig` and the
  `KinetixNumberField` Vue component (with a `compact` mode for table cells).

## [0.32.0] - 2026-06-26

### Added

- **Wizard `stepper` variant — the official shadcn/Reka Stepper** — built on
  `reka-ui`'s Stepper primitives (numbered indicators with titles, descriptions
  and connecting separators), with a new **`orientation`** prop
  (`horizontal` | `vertical`). It is now the **default** wizard variant for both
  the standalone `<KinetixWizard>` and the `Wizard` form layout. The previous
  designs (`default`, `simple`, `vertical`, `panels`, `gradient`) remain
  available.

### Changed

- The default wizard variant is now `stepper` (was `default`).

### Changed

- **Social auth icons are now monochrome by default** — `KinetixSocialButton`
  and `KinetixConnectedAccounts` render brand glyphs in `currentColor` so they
  contrast with the light/dark theme. Pass **`colorized`** to use each
  provider's true brand color. (The multicolor Microsoft mark always keeps its
  colors.) Replaces the previous `branded` prop (which defaulted to on).

## [0.30.0] - 2026-06-26

### Added

- **`RichEditor` form field** (`rich-editor`, **published**) — a rich text /
  WYSIWYG field with **three swappable editor drivers**, chosen globally via
  `config('kinetix.forms.rich_editor')` or per field with `->editor()` /
  `->basic()` / `->tiptap()` / `->markdown()`:
  - **`basic`** *(default)* — zero-dependency contenteditable + toolbar (HTML).
  - **`tiptap`** — the headless WYSIWYG standard (`@tiptap/core` +
    `@tiptap/starter-kit`, MIT), styled with your shadcn tokens. Loaded **lazily**
    so it stays an optional dependency; selecting it without installing shows an
    inline install notice.
  - **`markdown`** — zero-dependency textarea + live preview (Markdown), with a
    tiny HTML-escaping preview renderer.
  - Vue `KinetixRichEditor` (+ `Basic` / `Markdown` / `Tiptap` sub-components),
    new `forms.rich_editor` config block, and `FormFieldData.editor`.
  - HTML is **not** sanitized server-side — escape/sanitize on output (documented).
- New i18n keys (en/es/fr/pt): `editor_write`, `editor_preview`,
  `editor_tiptap_missing`.

## [0.29.0] - 2026-06-26

### Added

- **`KinetixSocialButton` + bundled brand icons** (extends Connected Accounts) —
  a reusable single-provider social-auth button: pass `provider` and it renders
  the brand icon + label and links to the right OAuth route (`mode="login"` for
  guest sign-in, `mode="link"` to attach to the current user). Props:
  `provider`, `mode`, `label`, `branded`, `block`, `variant`, `href`.
  - New **local brand icon components** under `resources/js/icons/brands/`
    (no runtime icon dependency), resolved via `@/icons/brands` (`brandFor()`):
    **github, google, microsoft, gitlab, bitbucket, facebook, x (twitter),
    apple, discord, twitch** + a generic fallback for any other provider.
  - `KinetixConnectedAccounts` now renders provider icons from this shared
    registry.
- New i18n key (en/es/fr/pt): `continue_with`.

## [0.28.0] - 2026-06-26

### Added

- **Browser Sessions / device management** (`sessions`, optional) — a modern,
  shadcn-styled take on Jetstream's browser-sessions panel:
  - Lists the user's active sessions (device type, browser, platform, IP,
    relative last-active) with a **"this device"** badge, reading Laravel's
    `sessions` table (requires `SESSION_DRIVER=database`).
  - **Log out other sessions** — password-gated (skipped for passwordless
    users); deletes every other session row, keeping the current one.
  - Ships a tiny built-in `UserAgentParser` (no `jenssegers/agent` dependency).
  - Backed by `BrowserSessionManager`, `BrowserSessionData`, `SessionController`
    and the `useKinetixSessions` composable + `KinetixSessions` Vue component.
    No migration is published (reads the existing `sessions` table).
- New i18n keys (en/es/fr/pt): `session*`.

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
