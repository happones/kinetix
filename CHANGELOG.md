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

### Added

- ⚠️ **(published) Form grids are responsive, with Filament's semantics.**
  `columns(int)` now means "N columns from `lg` up, ONE below" — so
  `Grid::make(2)` finally collapses on narrow layouts instead of cramming two
  columns into a phone. Both `columns()` and `columnSpan()` accept breakpoint
  maps (`['default' => 1, 'sm' => 2, 'xl' => 3]`; keys
  default/sm/md/lg/xl/2xl, values carry forward) on Grid, Section, Fieldset,
  Tab and wizard Step. Breakpoints are measured against the **form's own
  width** via CSS container queries — a two-column form inside a narrow modal
  collapses even on a wide viewport — and spans clamp to the columns available
  at each size, so a span can never overflow its grid. Implemented as inline
  CSS variables consumed by static `@container` rules (Tailwind's JIT never
  sees a dynamic class). New `useKinetixResponsiveGrid` composable + specs.
- **(published) The table toolbar arranges itself.** New
  `Table::toolbarLayout('auto'|'inline'|'stacked')` — the default `auto`
  adapts to the TABLE's own width: narrow tables stack heading → full-width
  search → a wrapping, right-aligned control row (saved views / actions /
  filters / column toggle); wide tables inline everything on one row. Pin
  `inline` or `stacked` to force one arrangement. Pagination's page label no
  longer wraps mid-phrase on narrow footers. Verified with multi-width
  screenshots (375/640/900) in the gallery's new `table-toolbar` and
  `form-responsive` specimens.

### Fixed

- **(published) Member-list row controls overflowed the card on narrow
  widths** (the "Resend"/"Remove" buttons escaped the border at ~375px); the
  control cluster now wraps inside the card.

## [0.134.0] - 2026-08-03

Five fixes: four from a consuming-app field report, plus the Tiptap driver's
loading model — the same optional-import defect the report exposed for
vue-virtual, found and fixed one component over.

### Fixed

- ⚠️ **(published) The Tiptap rich-editor driver could never load in a
  production build.** Its `import(/* @vite-ignore *​/ '@tiptap/core')` survived
  the host's build but was left unresolved in the bundle — the browser cannot
  resolve bare specifiers, so even apps WITH tiptap installed got the "install
  tiptap" notice (verified with build probes). No import shape inside a
  published component can be optional at build time AND resolvable at runtime,
  so the engine is now **host-registered**: apps that want the driver call
  `registerKinetixTiptap()` in their entry file with their own dynamic imports
  (statically resolved by their build, still code-split). New
  `useKinetixRichEditorEngine` composable, updated install notice in all seven
  locales, docs with the registration snippet, and the gallery registering as
  a real host.

- ⚠️ **(published) Migrations no longer hardcode `unsignedBigInteger` for
  columns referencing YOUR models.** `membership.user_model` (and the auth
  provider model) were always configurable, and nothing in Kinetix requires an
  auto-increment PK — but every `user_id`/`team_id`/morph column shipped as
  bigint, so UUID/ULID apps broke at runtime. All 21 affected migrations now
  build those columns with `Happones\Kinetix\Support\HostKeys`, which types
  each column after the app's model at migrate time (`HasUlids` → `ulid`,
  `HasUuids` → `uuid`, string `$keyType` → `string`, else bigint; the team
  model derives from the user's `teams` relation). New `kinetix.key_types`
  config pins a type when detection can't see the setup; morph ids follow
  `kinetix.key_types.morph` (default bigint) since a morph can target any
  model. v0.133.0's manual-retype docs and skill notes are rewritten around
  the automatic behaviour; apps that already migrated on bigint still need
  their own `ALTER` path.
- **`@tanstack/vue-virtual` is now a required peer** (and a core
  `kinetix:install` dependency). It was flagged optional while
  Comments/Kanban/MediaLibrary import it statically — any host compiling the
  published components without it failed its build, and no dynamic-import
  shape fixes that (a plain dynamic import still fails the host build when
  missing; `/* @vite-ignore */` survives the build but never resolves at
  runtime, even when installed). A peer that breaks the host's build is not
  optional; `--tanstack` now covers only `@tanstack/vue-table`.
- **`kinetix:upgrade` no longer reports upstream changes as "local edits".**
  Drift was measured against the package's NEW sources, so after a version
  jump every file changed upstream was flagged (the "113 published files had
  local edits" noise on 0.119→0.132). Each publish now records a hash manifest
  (`storage/app/kinetix-published-manifest.json`), and drift compares the disk
  against what the last publish actually wrote — which is the difference
  between "you edited this" and "the package shipped a new version". Without a
  baseline (first run after this ships) nothing is claimed; the run records
  one and says so.
- **(published) Both membership role selects render the same labels.** The
  provisioner headline-cased role slugs (`support-agent` → `Support Agent`)
  while the member list's per-row select showed the raw slug on the same
  screen. The helper is now shared (`roleLabel` in `useKinetixMembers`).

## [0.133.0] - 2026-08-03

The accessibility manifest's remaining remediation (live-region announcements
+ a keyboard alternative for every drag-and-drop surface), plus UUID/ULID
host-model guidance wired into the docs and every affected agent skill.

### Added

- **(published) UUID / ULID host-model guidance, for humans and agents.**
  Kinetix migrations type every column referencing a HOST model (`user_id`,
  `team_id`, morph ids, `invited_by`/`created_by_id`/`launched_by_id`) as
  `unsignedBigInteger`; on apps whose User/Team (or commented/tagged/audited
  models) use UUIDs or ULIDs those columns must be retyped before migrating.
  The recipe now lives in three layers: a dedicated installation-docs section
  with the full feature → publish-tag → columns table (including the
  spatie-pivot exception in `permission-team` and the "don't touch
  Kinetix-internal ids" rule), a canonical step-by-step section in the
  `kinetix-boost` agent skill (check the key type with `database-schema`
  first, publish, retype only matching columns, mixed keys per column, ALTER
  path for already-migrated apps), and a tailored note in each of the 18
  affected feature skills naming its exact columns and tag. The development
  skill now requires new features with host-model columns to ship both notes.

- **(published) Every drag-and-drop surface now has a keyboard alternative**
  (accessibility manifest rule 4 — pointer-only interactions are a defect):
  - **Reorderable table rows**: the grip is now a focusable button —
    Arrow Up/Down moves the row, the move is announced ("Moved to position 3
    of 12"), and persistence is debounced so an arrow-key burst costs one
    request instead of one per press.
  - **Kanban cards**: cards are focusable — Arrow Left/Right moves the card to
    the adjacent column (same optimistic move + revert as dropping), the move
    is announced with the column label, focus follows the card, and an
    `sr-only` hint teaches the interaction.
  - **Repeater items**: the existing move up/down/remove icon buttons gained
    translated `aria-label`s, decorative-marked icons and visible focus rings.
  - New keys (`move_up`, `move_down`, `row_moved`, `kanban_keyboard_hint`,
    `kanban_moved_to`) in all seven locales.
- **(published) Table result counts are announced to screen readers.**
  Search/filter/sort/page changes swap the rows with no focus change, so both
  table variants now speak the outcome through the shared polite live region
  (`useKinetixAnnounce`, previously shipped but unused): "Showing 1 to 10 of
  25 results", or the empty state. Announcements key off the table STATE — a
  `poll()` refresh that only swaps records stays silent. Action toasts and
  import/export toasts were already announced (vue-sonner renders
  `role="status"` + `aria-live="polite"`), so they are deliberately not
  double-wired. New `results_count` key in all seven locales for
  non-paginated tables.

## [0.132.0] - 2026-08-03

Chart theming that actually lands on starter-kit apps, plus the first
remediation pass of the accessibility manifest (tables + forms).

### Fixed

- ⚠️ **(published) v0.131.0's chart axis/grid theming silently no-oped on
  complete-color hosts — including the Laravel + Vue starter kit.** The
  `--vis-*` mapping wrapped every token in `hsl()` from CSS
  (`hsl(var(--border))`), which is only valid when the host stores HSL
  triplets (the kinetix.css style). A host storing complete colors
  (`--border: hsl(0 0% 92.8%)`, the starter-kit style) produced invalid
  `hsl(hsl(…))` values that the browser drops silently, so unovis fell back to
  its own defaults — the near-white dark-mode grid v0.131.0 claimed to fix was
  still there on those apps. The eight surface properties (axis grid/tick/
  domain/labels, crosshair line/circle, donut segment stroke) are now resolved
  in JS through the same both-shapes normalizer the series palette already
  used, bound as an inline style and re-resolved live by the existing
  dark-mode observer. Verified in-browser against both token shapes.
- Documented that a stock shadcn theme defines only `--chart-1`…`--chart-5`:
  series 6-8 fall back to Kinetix's validated palette unless the host defines
  the extended slots.

### Added

- **(published) Tables & Forms honor the accessibility manifest** (first
  remediation pass of the 10-rule manifest in the development skill):
  - Sortable column headers expose `aria-sort`
    (`none`/`ascending`/`descending`) that flips with the active sort; sort
    icons are marked decorative.
  - The select-all and per-row selection checkboxes have translated
    `aria-label`s; the reorder/actions header cells announce translated
    `sr-only` names (previously hardcoded English).
  - Pagination controls live in a labeled `<nav>` and every icon-only pager
    button (first/previous/next/last) has a translated `aria-label`.
  - An errored form field now sets `aria-invalid` (which also activates the
    shadcn destructive border/ring on inputs) and `aria-describedby` pointing
    at its error text, which renders with a stable `<name>-error` id and
    `role="alert"` so screen readers announce it on appearance. Wired through
    the field dispatcher via fallthrough attrs, so all ~30 field types get it
    without per-field plumbing.
  - New keys (`pagination`, `first_page`, `previous_page`, `next_page`,
    `last_page`, `select_row`, `actions`, `reorder`) in all seven locales.
  - Specs assert the wiring: `aria-sort` flip, labeled pager nav, error id ↔
    `aria-describedby` linkage.

## [0.131.0] - 2026-08-03

Chart widgets and stat cards, retouched end to end: working tooltips, automatic
interactive legends, a colorblind-validated theme-token palette with dedicated
dark-mode steps, and table stat cards that support trend colors and sparklines.

### Fixed

- ⚠️ **(published) Chart tooltips never rendered.** unovis' `Tooltip` component
  has no `template` prop, so the donut tooltip was silently dead, and
  `Crosshair` without a template renders an empty string — line/area/bar
  tooltips too. The XY tooltip now goes through the crosshair (with per-series
  dots at the series' *visual* height), and the donut binds a per-segment
  trigger; its tooltip also shows the slice's share of the total (e.g.
  `Direct: 4200 · 41.2%`).
- **(published) Axis/grid styling never applied.** The scoped
  `.vis-axis-*` selectors don't reach unovis' emotion-generated class names, so
  charts ran on library defaults — most visibly a glaring near-white grid in
  dark mode. Styling now flows through unovis' own CSS custom properties, mapped
  to the shadcn tokens (`--border`, `--muted-foreground`), so both modes derive
  from the theme with no duplicate dark rules.
- **(published) Stacked-area line overlays sat at raw values.** With multiple
  series, unovis stacks the areas but the decorative `VisLine` overlays were
  drawn at each series' raw value — floating mid-band instead of tracing the
  band's top edge. Overlays (and crosshair dots) now use cumulative accessors
  when stacking; non-stacked area charts render one translucent area per series.
- **(published) Per-slice `backgroundColor` arrays were ignored** by pie/donut
  and horizontal-bar charts; they now win over the theme palette, per slice.
- **(published) Stat-card sparkline gradients used fixed element IDs**, so two
  stats widgets on one page produced duplicate SVG IDs; colors were hardcoded
  hex that ignored dark mode. See the sparkline entry under *Added*.
- **(published) `useKinetixIcons` lacked names the docs advertise** — `book` and
  `chart-bar` (both used in `Table::stats()` examples) resolved to nothing, and
  `trending-down`, `percent`, `arrow-up-right`, `arrow-down-right`, `book-open`,
  `chart-column` and `bar-chart` are now available too.
- The gallery's `useKinetixHttp` stub was missing `xsrfToken`, which broke every
  gallery page (and screenshot run) since the Precognition bridge landed.

### Changed

- ⚠️ **(published) Chart legends are automatic.** `ChartWidget::legend()`'s
  default moved from off to **auto**: the legend shows whenever the chart has
  two or more series/categories, since color is the only thing disambiguating
  them. `legend(false)` forces it off, `legend()` forces it on. Legend entries
  are now buttons that toggle their series — hiding one never repaints the
  survivors (colors stay keyed to the original index), and the last visible
  entry can't be hidden, so a chart never renders empty.

### Added

- ⚠️ **(published) Theme-token chart palette, validated for accessibility.**
  Series colors resolve from `--chart-1`…`--chart-8` (the shadcn convention,
  extended to 8 slots in `kinetix.css`), with **separately tuned light and dark
  steps** — both validated for adjacent-pair colorblind separation and ≥3:1
  contrast against their surface. The previous hardcoded palette failed
  validation (pink vs rose were indistinguishable at ΔE 8.3 even with full color
  vision). A new `useKinetixChartPalette` composable resolves the tokens at
  runtime — accepting HSL-triplet or complete-color (`oklch(…)`/hex) values, so
  a shadcn starter kit's existing `--chart-N` variables just work — and
  re-resolves live when `html.dark` toggles, whichever toggle flips it.
  Dataset `borderColor`/`backgroundColor` still win. Entrance animations now
  respect `prefers-reduced-motion`, bars gained rounded corners and padding,
  and donuts gained segment gaps + corner radius per the mark specs.
- **(published) `KinetixSparkline`** — the stat-card sparkline, extracted into a
  shared component used by both the stats-overview widget and table stat cards.
  It inherits its tint from the status *tokens* via `currentColor` (stroke and
  gradient fill), so it re-tints with dark mode and host re-skins; gradient IDs
  are per-instance (`useId`), and the draw-in animation respects
  `prefers-reduced-motion`.
- **(published) `TableStat` trend presentation** — mirroring the `Stat` widget
  API: `descriptionIcon()`, `descriptionColor()` (renders the description as a
  colored chip) and `chart([...])` (a sparkline tinted by the trend color,
  falling back to the card color). The card layout moved the description to its
  own full-width row so chips and sparklines don't fight for space in narrow
  grids.
- Gallery specimens `stats-sparkline` and `table-stats`, with light/dark
  screenshots wired into `docs/widgets.md` and `docs/tables.md`.
- Tooling: husky `pre-commit` (lint-staged: pint + eslint + prettier on staged
  files), `commit-msg` (commitlint, conventional commits) and `pre-push`
  (vue-tsc + phpstan) hooks, installed via the `prepare` script.

## [0.130.1] - 2026-08-03

A patch over v0.130.0, whose tagged commit had a red pipeline. **If you installed
v0.130.0, upgrade** — one of its two defects is in a published file.

### Fixed

- ⚠️ **(published) `resources/js/types/kinetix.ts` declared
  `KinetixChartDataset` twice.** TypeScript merges same-name interfaces, and the
  two declarations disagreed on `label`, `data`, `backgroundColor` and
  `borderColor` — so an app that republished the types and ran `vue-tsc` got
  TS2687/TS2717 errors in *its own* type check. Unified into one declaration that
  is a superset of both: array colours and `borderWidth` from the older one,
  optional/nullable `label` and mixed `data` from the newer, plus an index
  signature (typed `unknown`, not `any`) for the widget's open `data` payload.
- **`Table::getResolvedQuery()` no longer mutates the builder you passed to
  `Table::make()`.** The request's search/sort/filters were applied to that very
  instance, so reusing `$query` after rendering the table silently inherited the
  table's filters, and rendering twice piled the same clauses on again. The table
  now resolves onto a fresh builder; your own constraints are preserved and still
  bound what the table's filters can reach.
- `ExportImportSecurityTest` dispatched a real queued job, which failed under the
  `testbench package:test` runner CI uses — its config bootstrapping ignores
  `phpunit.xml`'s `QUEUE_CONNECTION=sync` and fell back to the database driver,
  with no `jobs` table in the in-memory database. It fakes the queue now, which is
  also the better assertion.

### Added

- `composer test:ci` and `npm run types:check` mirror the two CI commands that
  differ from the obvious local ones (`testbench package:test` and `vue-tsc`), so
  a green local run actually predicts a green pipeline.

## [0.130.0] - 2026-08-03

### Added

- **(published) `Table::stats()` — KPI cards above a table.** Counts, sums,
  averages, mins and maxes over the same dataset the table lists, rendered as the
  familiar label + big value + coloured icon cards:

  ```php
  Table::make(Book::query())->stats([
      TableStat::make('Total books')->count()->icon('book'),
      TableStat::make('On loan')->count()->where('status', 'loan')->color('warning'),
      TableStat::make('Overdue')->count()->where('due_at', '<', now())->color('danger'),
      TableStat::make('Inventory value')->sum('price')->money('USD'),
  ]);
  ```

  Available on Resources for free, since `Resource::table()` receives the same
  `Table`. Cards support `where()`/`whereNull()`/`whereNotNull()` conditions,
  `icon()`, `color()`, `description()`, `url()`, the summarizer formatting helpers
  (`numeric()`, `money()`, `prefix()`, `suffix()`), and `visible()`/`hidden()`/
  `can()` — a card the user may not see is never computed at all.

  **Each card's condition compiles into a conditional aggregate inside one shared
  query**, so twelve cards cost the same single extra query as one. That is the
  point of the feature rather than stacking `Summarizer::query()` scopes, which
  cost one query *per* card; a test asserts the batching holds. Cards follow the
  table's active filters by default (like the footer summaries) and always cover
  the whole filtered set, never one page; `ignoreFilters()` opts a card out, and
  those share a second query with each other. `using()` remains as an explicit,
  documented one-query-of-its-own escape hatch.

- **(published) `KinetixLanguageSwitcher` renders in two shapes.** A new
  `variant` prop picks between `dropdown` (default — the existing `Languages`
  icon + menu, for a header or toolbar) and `select` (a labelled Select field,
  for a settings page or profile form), so the same state can be exposed in two
  very different places. The select variant is built on `KinetixSelect`, so it
  inherits the keyboard navigation and styling of every other Select, and takes
  `label` to override its text and `:show-label="false"` to keep only an
  `aria-label`. Each shape lives in its own subcomponent under
  `components/LanguageSwitcher/`.

### Changed

- Numeric/money formatting shared by summarizers and stat cards moved into a
  `FormatsAggregateValue` concern, so a sum formatted as money reads identically
  in the footer and in a card. No behaviour change.
- `KinetixTable`'s root is now a wrapper holding the stat cards plus the table
  card. Attributes are forwarded explicitly, so an existing
  `<KinetixTable class="…">` still applies to the table card as before.

### Fixed

- **Two language switchers on one page no longer drift apart.** `current` was a
  per-instance `ref` seeded from the Inertia prop, so a header dropdown and a
  settings select each held their own copy: switching in one left the other
  showing the previous locale until a full page reload. It is now derived from
  vue-i18n's locale — a single ref per Vue app, and still per-request under SSR,
  unlike a module-level ref would be. This is what makes using both variants at
  once work.

## [0.129.0] - 2026-08-03

A hardening release from a full-package audit. Nothing here adds features; it
closes a cross-tenant write hole, makes the package deployable under
`route:cache`, and fixes a publish gap that broke the host's frontend build.

### Security

- ⚠️ **Fixed: inline cell edits and reordering could write another tenant's
  rows.** `_kinetix/tables/cell-update` and `.../reorder` resolved the record
  with a bare `Model::find()` and ran **no authorization at all**, so any
  authenticated user holding a valid table token could write to any record of
  that model by changing `recordId`. Both endpoints now live in
  `TableWriteController` and are guarded on four axes: the record resolves
  through the table's own scope, the model's policy is enforced (`update`, or
  `Table::writeAbility()`), the descriptor is bound to the user it was minted
  for, and it expires (`kinetix.tables.token_ttl`, default 24h). Reordering also
  saves through the model, so host observers and audit logs fire again, and
  `ids: [[1,2]]` can no longer mass-assign one position.
- **Signed descriptors are now user-bound and expiring** across tables, kanban
  boards, searchable Selects and TableRepeater autosave. Encryption proved
  Kinetix minted a payload but not that the presenter was entitled to it, so an
  admin's token — which embeds a wider editable-column allowlist — was replayable
  by anyone who obtained it.
- **Exports and imports are authorized.** `Exporter::authorize()` /
  `Importer::authorize()` (overridable, `viewAny`/`create` by default when the
  model has a policy) now gate `exports/start` and every import endpoint. An
  import is a write primitive and an export could previously be pointed at
  arbitrary `ids`.
- ⚠️ **Generated artifacts moved off the public disk.** Exports, uploaded import
  files, report runs and GDPR personal-data dumps now use
  `kinetix.filesystem.private_disk` (default `local`). On a public disk they were
  served at a guessable `/storage/...` URL with **no authentication**, making the
  token-guarded download route a side door. `kinetix.filesystem.disk` still
  serves uploads and image URLs.
- **Download links are bound to their recipient and expire**
  (`kinetix.exports.download_ttl`). The GDPR dump link was documented as
  one-time; it was neither bound nor expiring.
- **Spreadsheet formula injection is neutralized** in CSV and XLSX exports.
  Cells starting with `=`, `+`, `-`, `@` were written verbatim, and
  PhpSpreadsheet typed a leading `=` as a real formula — so exported user content
  like `=HYPERLINK(...)` exfiltrated the row of whoever opened the file.
- **Uploads reject browser-executable files by default.** A `FileUpload` with no
  `accept()` took anything; on the public disk an uploaded `.html`/`.svg` was
  stored XSS on the app's own origin. See
  `kinetix.filesystem.upload_blocked_extensions` and `upload_max_size`.
- ⚠️ **Uploads are stored per user** (`kinetix.filesystem.scope_uploads_by_user`,
  default on), which is what stops one user deleting another's file — uploads
  previously shared one flat directory with no ownership record. Paths for new
  uploads gain a user segment.
- **Membership provisions are team-scoped on the admin endpoints.** An admin of
  one team could update, resend (leaking an activation link) or revoke another
  team's invitation by id.
- **Impersonation can no longer be laundered into role management.** A user
  holding `users.impersonate` could impersonate an admin holding `roles.manage`
  and inherit the session. See `kinetix.impersonation.protected_permissions`.
- **Searchable Selects can be bounded** with `Select::searchScope()`; without a
  query modifier they returned every tenant's labels 20 rows at a time.
- **TableRepeater no longer mints a write token for a read-only render**
  (`view` operation or a disabled field), and its autosave endpoint enforces the
  parent record's policy.
- **Smaller fixes:** `perPage` is clamped (`kinetix.tables.max_per_page`) so a
  crafted value can't hydrate a whole table into one payload; `AddressFilter`
  escapes LIKE wildcards; PDF template colours are validated instead of
  HTML-escaped into a `<style>` block; `MediaManager::sync()` constrains
  client-supplied paths and only reorders media the record owns.

### Fixed

- ⚠️ **`php artisan route:cache` works.** Ten routes (notifications, table
  cell-update/reorder, kanban-move) were registered as closures, so the standard
  production deploy step failed for **every** app with Kinetix installed. They
  are controllers now, and a test asserts the whole route surface stays
  serializable.
- ⚠️ **The published frontend builds.** `resources/js/icons` and
  `resources/js/plugins` were missing from the `kinetix-components` publish tag,
  while shipped components import `@/icons/kinetixBrands` and the docs instruct
  registering `@/plugins/kinetix*` — so a host following the docs hit an
  unresolvable import.
- **Vue dialogs are accessible.** `KinetixConfirmModal` and `KinetixSheet` had no
  focus trap, initial focus, focus restore or `aria-labelledby`; Tab reached the
  page behind the overlay. Extracted as `useKinetixFocusTrap`.
- **Kanban columns rendered every card at the same position.** `KanbanColumn`
  read `virtual.enabled` (a always-truthy `Ref`) in its template instead of
  `.value`, so it used virtualized markup with a window of 0.
- **Queued exports, imports and GDPR dumps report failures.** None declared
  `tries`/`backoff`/`failed()`, so a thrown job died in `failed_jobs` and the
  user who was promised a notification never heard anything. Import failures now
  name the row number and reason instead of only a count.
- `validateEnvironment()` no longer reads and JSON-decodes `package.json` on
  every production request (and can no longer hard-fail one); it runs in
  local/testing, and `kinetix:doctor` covers it on demand.
- The `kinetix.permissions.team` middleware is no longer pushed without its
  `class_exists` guard on the PDF-templates and API-logs routes, which threw on a
  teams-enabled host without spatie/laravel-permission.
- Connected Accounts warns and skips route registration when laravel/socialite is
  absent, instead of fataling at request time.
- Timers in `KinetixTableRepeater` (now flushed on unmount, so a pending autosave
  isn't lost), `KinetixMailTemplates`, `useKinetixIntegrationLogs`,
  `useKinetixPrecognition`, `KinetixCopyableInput` and `KinetixInfolistEntries`
  no longer fire after the component is gone.

### Changed

- ⚠️ **(published) Vendor-managed files that shared a host directory were
  renamed** so a publish can never claim a filename the app might own:
  `stores/notifications.ts` → `stores/kinetixNotifications.ts`,
  `stores/tours.ts` → `stores/kinetixTours.ts`,
  `composables/useMasonryColumns|useShadcnVariants|useStatusColor` →
  `useKinetixMasonryColumns|useKinetixShadcnVariants|useKinetixStatusColor`,
  `icons/brands*` → `icons/kinetixBrands*`. Update your imports; a test now
  enforces the naming.
- `Table/KinetixTableCell.vue` uses a component map (12 cell components) instead
  of an 11-branch `v-else-if` chain in the hottest render path.
- The `MediaLibrary` grid virtualizes past a threshold, like Kanban and Comments.
- Widgets serialize through a `WidgetData` class, so widget payloads get a
  generated TypeScript contract like every other builder.
- User-facing endpoint messages are translated (7 locales) rather than hardcoded
  English; `trans()` call sites are unified on `__()`.
- `.gitattributes` keeps docs, tests, the gallery and dev config out of the
  Packagist dist — 1602 → 933 files for every consumer.
- `composer.json` `suggest` now lists Horizon, spatie/laravel-health,
  spatie/laravel-medialibrary and Cashier, which the code already guards for.

### Added

- Tests for the above: `TableWriteSecurityTest`, `ExportImportSecurityTest`,
  `TableRepeaterSecurityTest`, `RouteCachingTest`, `BillingHttpTest` (the billing
  HTTP layer had no coverage at all) and `MiddlewareEnforcementTest`, which runs
  with the real `web`+`auth` stack the rest of the suite clears and asserts every
  route carries it. 947 → 989 PHP tests, 577 → 616 JS tests.

## [0.128.0] - 2026-08-03

### Changed

- ⚠️ **BREAKING — the form grid now matches Filament.** A field's default
  `columnSpan` is **1** (was `'full'`), and the column counts moved with it:

  | | Before | Now |
  |---|---|---|
  | Form root | 12 columns | **1** |
  | `Grid::make()` | 12 | **2** |
  | `Section` / `Fieldset` / `Tab` / `Step` | 12 | **1** |
  | Field `columnSpan` | `'full'` | **1** |

  The two defaults only make sense together: a 1-column root means a plain
  field's span of 1 *is* full width, so **simple forms render identically**,
  while `Grid::make(2)` now yields two columns without annotating every field —
  the DX this change is for.

  **What breaks:** a field with **no** explicit span inside an explicit
  `Grid::make(12)` (or a `->columns(12)` section) used to fill the row and now
  takes 1/12. Fix by adding `->columnSpanFull()`, or by dropping the explicit
  `12` and letting the new default apply. Explicit spans inside explicit grids
  (`Grid::make(12)` + `->columnSpan(6)`) are unaffected.

  Infolists keep their own 12-column system — this touches forms only.

### Added

- `FormGridDefaultsTest` pins the whole contract (root, per-layout column counts,
  default and explicit spans). It had **no** coverage before, which is why the
  defaults could drift silently.

## [0.127.0] - 2026-08-02

### Added

- **`Select::relationship($name, $titleColumn, $modifyQueryUsing)`** — the API
  `SelectFilter` already had, now on the form side. The relation names the
  related model and its key, so this replaces repeating them in `options()` /
  `searchUsing()`:

  ```php
  Select::make('author_id')->relationship('author', 'name');
  Select::make('author_id')->relationship('author', 'name', fn ($q) => $q->where('active', true));
  ```

  **Inherited by `CheckboxList` and `Radio`**, which extend `Select` — the three
  option-backed fields, matching the set Filament supports.
- `Form` hands its model to relationship-aware fields (the new
  `ResolvesRelationships` contract), mirroring what `Table` already did for
  filters. The model comes from `Form::model()` or is inferred from the record
  the form was filled with; without one the field falls back to `options()`
  rather than throwing.
- **`relationship()` composes with `searchable()`**: the remote-search token is
  derived from the relation, so the model and label column can't disagree. The
  query modifier travels in the token **only as the class-string of an invokable
  class** — the token round-trips through the browser and a closure isn't
  serializable, the same constraint (and solution) as the config callbacks. A
  closure still shapes the eagerly-loaded options.
- `kinetix.forms.relationship_options_limit` (default 200).

### Fixed

- **`SelectFilter::relationship()` loaded every related row.** Its options query
  had no limit, so a relation over a large table put the whole thing in the page
  payload. It is capped now, and logs a warning naming the filter when the list
  is truncated instead of silently showing a partial set.

### Notes

- `Repeater` / `TableRepeater` do **not** get `relationship()`. Filament's
  version of it persists a HasMany (creating, updating and deleting child rows on
  save) — that is a persistence feature, not an options one, and it deserves its
  own design rather than riding along here.

## [0.126.0] - 2026-08-02

### Performance

- **Column summaries now share a single aggregate query.** Each summarizer ran
  its own — a footer with sum + average + count + range over one column was
  **five** scans of the filtered set (Range counts twice, for min and max), and
  columns multiplied it further. They are folded into one
  `select sum(…), avg(…), min(…), max(…), count(*)`.

  This mattered most exactly where it hurt: the tables big enough to want
  `simplePaginated()` / `cursorPaginated()` were the ones paying N scans for
  their footer, which undercut the point of dropping the `COUNT(*)`.

  A summarizer with a `query()` scope or a `using()` callback measures a
  different dataset, so it keeps its own query — the cost is `1 + (those)`.
  Values, formatting and the hidden/visible rules are unchanged; the existing
  summary tests pass untouched.

### Added

- `Summarizer::aggregateExpressions()` / `summarizeFromValues()` / `isBatchable()`
  — the contract a custom summarizer implements to join the shared scan. Not
  implementing it is safe: the summarizer simply runs its own query, as before.

## [0.125.0] - 2026-08-02

### Added

- **`Table::cursorPaginated()`** — seek-based pagination. `simplePaginated()`
  removed the `COUNT(*)`, but `OFFSET` remained: `LIMIT 10 OFFSET 50000` makes
  the database walk and discard 50,000 rows, so deep pages get linearly slower.
  A cursor seeks with `WHERE (sort, id) > (…)` through the sort's index, so every
  page costs the same.

  **It appends the primary key to the sort**, which is the part that matters.
  A cursor is built from the `ORDER BY` columns, so a non-unique sort makes the
  next page resume *after that value* and step over the rest of a tied group —
  silently. In the test fixture, walking every page of a 6-row table sorted by a
  2-value column returns **4 rows** without the tiebreaker and 6 with it. Nothing
  errors either way, which is why this is enforced rather than documented.

  Sorts a cursor cannot encode — a relation column (correlated subquery) or a
  custom `sortable(using:)` resolver — **fall back to `simplePaginated()` for
  that request** instead of paginating wrongly.
- `pagination.nextCursor` / `prevCursor` / `onFirstPage` on the table payload.
- The reload contract gained `cursor`. It is mutually exclusive with `page`, and
  is dropped automatically whenever the search, sort, filters or page size change
  — a cursor encodes a row under the ordering it was issued with, so resuming
  from it after a re-sort would seek to a meaningless position.

### Changed

- ⚠️ **`TablePaginationData.currentPage` is now nullable** (`null` in cursor
  mode — a cursor has no page number), joining `total` and `lastPage` from
  v0.124.0. `from`/`to` are also null in cursor mode: a seek has no offsets.

  A custom footer detects the mode by `pagination.currentPage === null` and
  navigates with the cursors. The bundled footer renders prev/next only, with no
  page indicator and no total. Tables using `paginated()` are unaffected.

## [0.124.0] - 2026-08-02

Delivers the pagination work deferred from v0.123.0.

### Added

- **`Table::simplePaginated()`** — paginate without the `COUNT(*)`. The default
  paginator counts the **filtered** query on every page load just to know the
  total; on a large table (or one with expensive filters) that count dominates
  the request, and it re-runs on every keystroke, filter change and page step.
  Simple mode fetches one extra row instead, to learn whether a next page exists.
- `pagination.hasMore` on the table payload — the reliable "is there a next page"
  signal in both modes. Custom footers should read it instead of comparing
  against `lastPage`.
- The four pagination buttons carry `data-testid` (`page-first`, `page-prev`,
  `page-next`, `page-last`), so tests and host code can target them without
  depending on DOM order — the per-page select renders a button too.
- Two locale strings in all 7 languages: `page_number` (*Page 2*) and
  `showing_range` (*Showing 11 to 20*), the countless variants of the footer
  labels.

### Changed

- ⚠️ **`TablePaginationData.total` and `.lastPage` are now nullable.** They are
  `null` in simple mode — the whole point is that the server never computed them,
  so rendering a placeholder would be a lie. The default paginator still sends
  both, so a length-aware table's payload is unchanged.

  A custom table footer that reads `pagination.total` / `pagination.lastPage`
  keeps working for `paginated()` tables; guard those reads before adopting
  `simplePaginated()`. The bundled footer drops the total line and the
  first/last jumps in simple mode rather than rendering empty numbers.
- `useKinetixClientTable`'s pagination gained `hasMore` too, so the client-side
  and server-side footers share one contract.

## [0.123.0] - 2026-08-02

### Fixed

- **Dot-notation table columns caused an N+1.** `docs/tables.md` promised
  `author.name` renders "without causing N+1 queries", but `Table` never called
  `->with()` — `data_get($record, 'author.name')` lazy-loaded the relation once
  **per row**, so a 25-row page fired 25 extra queries unless the caller
  remembered `Post::with('author')` by hand. The eager loads are now derived from
  the declared columns, so they cannot drift from what is rendered. A JSON path
  (`meta.color`) is not mistaken for a relation, and an already-loaded relation
  is not added twice.
- **LIKE wildcards in search input were not escaped.** Searching `100%` produced
  `%100%%`, which matches every row and scans the table; `_` behaved as a
  single-character wildcard. Terms are escaped with an explicit
  `ESCAPE '!'` clause — not a backslash, which MySQL and SQLite disagree about.
- **`kinetix:make-resource` scaffolded the tenant antipattern.** The generated
  `getEloquentQuery()` / controller used
  `request()->user()->currentTeam->id` — the exact resolution v0.120.0 removed
  from the package's own modules: it ignores the `{current_team}` segment, skips
  the membership check, and fatals when the user has no current team. It now
  generates `KinetixTeams::currentTeamKey()`, with the import (the generated file
  would not have parsed without it).

### Added

- **`Happones\Kinetix\Query\KinetixQuery`** — the query primitives every Kinetix
  reader shares, previously reimplemented in five places with subtly different
  behavior: `search()` (grouped OR, escaped, dot-notation aware),
  `escapeLike()`, `eagerLoad()`, `sortByRelation()` (correlated subquery, no
  join) and `direction()`. `Table`, the select-field search, Spotlight, and the
  API-log and webhook-log feeds all route through it.

  **Tenancy is deliberately not part of it.** Kinetix doesn't know the host's
  team schema; the caller supplies an already-scoped base query and
  `Resource::getEloquentQuery()` stays the documented seam. The grouping in
  `search()` is what makes that safe — OR terms can't escape the tenant filter.

### Notes

- Cursor/`simplePaginate` support for very large tables is **not** included: it
  changes the pagination payload the Vue component and TS types consume, so it
  belongs in its own release rather than riding along with a query refactor.

## [0.122.0] - 2026-08-02

### Added

- **API request logs are tenant-aware.** Rows carry paths, token names and
  optionally request/response bodies, and were one shared pool: any team's
  `viewKinetixApiLogs` holder read every tenant's traffic. Each row is now
  attributed to the caller's team — resolved from a team segment when your API
  route has one, otherwise from the **token holder's** `currentTeam`, which is
  what a typical session-less token route resolves to — and the viewer scopes
  strictly.

  Unlike mail templates and announcements, `NULL` here is **not** a shared
  default: a log belongs to exactly one tenant, so a NULL row is *unattributed*
  and the scope fails closed rather than showing it to everyone. Rows written
  before the migration therefore stop appearing inside a team's viewer — there
  is no way to know which tenant they belonged to — and age out with
  `kinetix:api-logs:prune`.

### Changed

- `kinetix:doctor` no longer warns about global data for modules that are now
  scoped. It states the two that remain platform-wide **by design** (billing
  plans, confidential keys) as an informational line, and covers
  `kinetix_api_logs` in the missing-`team_id`-column check.

### Upgrading

```bash
php artisan vendor:publish --tag=kinetix-api-logs-migrations --force
php artisan migrate
```

Additive and idempotent; single-tenant apps are unaffected whether or not they
run it (the column is only written while the module is team-scoped).

## [0.121.0] - 2026-08-02

Closes the last gap from the v0.120.0 tenancy audit: the two modules that stored
one shared pool of rows behind team-prefixed routes.

### Added

- **Mail Templates are tenant-aware**, in the hybrid shape roles already use:
  `team_id` NULL is a **global default** every tenant resolves, and a team may
  hold its own override under the same key (uniqueness moved from `key` to
  `(team_id, key)`). `KinetixMail` prefers the override and falls back to the
  default. Editing a global template from inside a team **forks** it
  (copy-on-write, `201` + `forked: true`) instead of rewriting the platform
  default for every tenant; deleting the fork reverts that team; deleting the
  default from a team scope is refused (`403`). A disabled override turns the
  mail off for that team only. Another team's template is a `404`.
- **Announcements are tenant-aware**: an entry belongs to the team it was
  published from, and `team_id` NULL is platform-wide — every feed shows it.
  `KinetixAnnouncements::publishGlobally()` is the explicit way to reach every
  tenant, and `publish()` already lands there when called with no team context
  (a deploy step or seeder). Feeds and unread counts are scoped to the team plus
  the global entries.
- `ScopedToTeam` gained `->forCurrentTeamOrGlobal()` (the hybrid scope) and
  `::teamAttributes()`, which writes `team_id` **only** while the module is
  team-scoped — so an app that upgrades without running the new migration keeps
  working against the old schema instead of erroring on a missing column.
- `kinetix:doctor` reports **tables missing their `team_id` column** when the
  module is team-scoped — the "published the package, forgot the migration"
  state, where scoping is silently inert.

### Fixed

- **Mail template edit / delete / test were broken with teams on.** The
  controller took the id as a positional argument, so the leading
  `{current_team}` segment was injected as the template id — every mutation hit
  the wrong row or 404'd. The id is now read by route-parameter name, the guard
  every other Kinetix controller already had.

### Upgrading

```bash
php artisan vendor:publish --tag=kinetix-mail-templates-migrations --force
php artisan vendor:publish --tag=kinetix-announcements-migrations --force
php artisan migrate
```

Both migrations are additive and idempotent. **Existing rows keep `team_id`
NULL**, which makes them the global defaults / platform-wide entries — nothing
disappears from any UI, and single-tenant apps are unaffected whether or not they
run them.

## [0.120.0] - 2026-08-02

Outcome of a full multi-tenancy audit across all 34 route registrars, 24
migrations and every module manager.

### Security

- **Reports Center was not tenant-isolated.** Both tables shipped a `team_id`
  column that was **never written and never filtered**, so with the module
  enabled any holder of `viewKinetixReportsCenter` could list, `run-now`, cancel,
  retry, **download the generated file of**, and delete another team's report
  schedules and runs. Schedules and runs are now stamped with the active team and
  every query — including the `findRun()` / `findSchedule()` lookups behind the
  mutations — is scoped to it.

### Fixed

- **Modules resolved the tenant from `$user->currentTeam`, ignoring the URL.**
  Activity, Settings, Webhooks, Onboarding and Wizards read and wrote the user's
  *stored* team rather than the team the request was serving, so on `/team-b/…`
  the audit feed showed team A and settings writes landed in team A. All of them
  now go through `KinetixTeams`, which reads the `{current_team}` segment (and
  performs the membership check) with `currentTeam` only as the out-of-request
  fallback.
- **A bound team model crashed every page.** The `kinetix_config` share
  interpolated the route parameter directly, so a host route-model binding on
  `{current_team}` — a case `KinetixTeams` explicitly supports — fataled with
  *"Object of class Team could not be converted to string"*. It now resolves
  through `getRouteKey()`.
- **Billing's `{team}` segment is recognized.** Billing (and Presence) mount
  under `{team}` while the core uses `{current_team}`; `KinetixTeams` read only
  the latter, so a Kinetix component rendered inside a billing page silently fell
  back to `currentTeam`. Both names are accepted now.

### Added

- **`useKinetixTeams().teamUrl()`** — the documented way to build team-aware
  links in your own Inertia pages: `teamUrl('/projects')` → `/acme/projects`, a
  no-op when teams are off, and idempotent so a server-generated URL can pass
  through it. Also `currentTeamKey`. The segment is the team's **route key**
  (slug/uuid), not the `id` exposed on a team option — interpolating the id
  produces URLs that 404 on slug-routed teams. Backed by a new
  `kinetix_config.team` prop, available from `kinetix.teams` alone (the switcher
  does not have to be enabled).
- **`KinetixTeams::keyFor('module')`** — one resolver for every module: honors
  the URL segment, checks membership, respects the module's tri-state `teams`
  flag, falls back to `currentTeam` outside a request.
- **`ScopedToTeam` trait** — `->forCurrentTeam()` + `::currentTeamId()` for any
  model with a `team_id` column. **Fails closed**: while the module is
  team-scoped the query is always constrained, and an unresolvable team matches
  `NULL` rows rather than every row (the failure mode that made the Reports
  Center leak invisible).
- **`kinetix:doctor` reports global-data modules** — Mail Templates,
  Announcements and API logs have no `team_id` at all, so their rows are shared
  across tenants even though their routes are team-prefixed. The doctor now names
  the ones you have enabled while teams are on.

### Documentation

- A **team-scoping coverage table** in `installation.md`: which modules scope per
  team, which are per user, which inherit from a parent record, and which are
  global. Route prefixing and data isolation are separate layers and the docs now
  say so instead of implying the first means the second.
- The exports endpoints are documented as **deliberately** outside the team
  segment (the download URL is built inside queued jobs, where requiring the
  parameter would throw), with guidance to capture the tenant into the job's
  parameters — this was verified against the existing test that asserts it.

## [0.119.0] - 2026-07-30

### Security

- **The team-owner bypass no longer short-circuits model policies.** `Gate::before`
  returning a blanket `true` (v0.118.0, and the snippet the docs used to suggest)
  also passed **policy** checks, so the owner of team A could `update`/`delete`
  team B's records — the tenancy boundary was gone. The bypass now only grants
  abilities **registered in the `PermissionRegistry`** (`posts.update`,
  `reports.access`, …); record-bound policy abilities fall through and your policy
  decides. `PermissionRegistry::has()` is public so a hand-written bypass can be
  scoped the same way, and the docs now show that shape.

### Changed

- ⚠️ **BREAKING — Kinetix publishes its TypeScript declarations to
  `resources/js/types/kinetix.ts`** instead of `types/index.ts`. In the Laravel
  starter kits `types/index.ts` is the **app's own barrel** (`export * from
  './auth'`, `'./teams'`, …); publishing over it stripped those re-exports, and
  because `@/types` still resolved, TypeScript silently degraded to `any` rather
  than erroring — entire component prop contracts stopped being checked with
  nothing in the output. Kinetix's own imports moved to `@/types/kinetix`.
  **Migration:** restore your barrel in `types/index.ts` and change Kinetix type
  imports to `@/types/kinetix`; `kinetix:upgrade` prints the reminder while a
  Kinetix-authored `index.ts` is still present, and `kinetix:doctor` flags it.
- **Config callbacks accept a callable array** — `[SyncProvisionedMember::class,
  'attach']`, resolved through the container (an instance method gets a
  container-resolved instance). Every documented example now uses it: the previous
  closure snippets were **not deployable**, since a closure in a config file makes
  `php artisan config:cache` abort with "value at … is non-serializable". An array
  is only read as a callback when it is a `[class-string, method]` pair, so
  `assignable_roles => ['editor', 'viewer']` is unaffected.

### Added

- **`php artisan kinetix:doctor`** — one command for every misconfiguration that
  otherwise fails silently: resolved route prefix, enabled modules, duplicated
  `kinetix.*` route names (a host controller shadowing a package route for
  `route()`), half-enabled team scoping, teamless roles, `attach_member` missing,
  empty `assignable_roles`, **closures in config that break `config:cache`**,
  duplicated i18n bundles, a legacy `types/index.ts`, and published files carrying
  local edits. Exits non-zero on errors so it can gate a deploy; `--json` for
  tooling.
- **`kinetix:upgrade` names the local edits it overwrites.** It runs from
  composer's `post-autoload-dump` and re-publishes with `--force`, so an edit in a
  published component used to disappear in CI with nothing to show for it. It now
  hashes the targets first and lists what differed.
- **Duplicated Vue i18n bundle detection.** `vue-i18n:generate` defaults to
  `--format=ts` while the generator config points `jsFile` at a `.js` path, so both
  files can exist — and Vite resolves `.js` first, meaning the compiled bundle is
  the one nothing refreshes (new keys never reach the UI). `kinetix:upgrade` and
  `kinetix:doctor` both flag it.
- **`kinetix:install` gitignores regenerated output** —
  `resources/js/vue-i18n-locales.generated.*` and `resources/js/types/kinetix.ts`
  are build artifacts the package rewrites on every `composer install`; versioning
  them means a diff on every branch that touches a translation. Only appended when
  the project already has a `.gitignore`.

## [0.118.0] - 2026-07-30

### Added

- **`php artisan kinetix:routes`** — lists every endpoint Kinetix registers with
  its **resolved** URI (`/{current_team}/_kinetix/permissions/roles`, …), name and
  (with `-v`) middleware; `--json` for tooling, an optional filter argument. The
  header states the prefix contract explicitly, and app routes that happen to sit
  under the same prefix are listed too, so collisions are visible. This closes the
  #1 integration mistake: writing a controller under a different path and waiting
  for a Kinetix component to call it (components own their URLs; the app registers
  only the Inertia page route).
- **Team-owner gate bypass** — `kinetix.permissions.owner_bypass` makes "the owner
  can do everything" a config line instead of a hand-written `Gate::before`.
  Ownership lives in the host's team schema, not in `model_has_roles`, so no role
  can grant it: `true` uses the host's `$user->ownsTeam($team)`, or pass a callback
  `fn ($user, $team) => bool`. The team is resolved from the `{current_team}`
  segment (falling back to `currentTeam`), the verdict memoized per user × team in
  a `WeakMap`, and the `kinetix_permissions` prop already reflects dynamic Gate
  grants — so an owner's UI matches the server without holding a permission row.
- **Dynamic `membership.assignable_roles`** — the allow-list now accepts a callback
  receiving the team key (array, Collection or `Role` models), for apps whose teams
  create their own roles in the Roles UI. Still enforced twice (provision +
  activation); at activation it resolves against the **provision's** team, since
  the signed URL carries no `{current_team}` segment.
- Callback config options (`permissions.owner_bypass`,
  `membership.assignable_roles` / `attach_member` / `detach_member`) also accept the
  **class-string of an invokable class**, which keeps `php artisan config:cache`
  working — a closure in a config file breaks it.
- **`vendor:publish --tag=kinetix-skills`** — the 46 bundled agent skills
  (`kinetix-permissions`, `kinetix-tables`, `kinetix-membership`, …) now reach the
  host. They were shipped in `resources/boost/skills` where no coding agent can see
  them; `kinetix:install` publishes them by default (`--skip-skills` opts out),
  `kinetix:upgrade` refreshes an adopted copy, and `kinetix.skills_path`
  (default `.claude/skills`) retargets other agent tooling.
- **Two new boot/console diagnostics** for silent failures:
  - `kinetix:permissions:sync` lists **global (teamless) roles** that aren't
    protected when team scoping is on — the classic "seeder ran without team
    context" accident, where roles become visible in every team and editable by
    super-admins only.
  - A boot warning when membership team scoping is on but `attach_member` is
    `null` — activated members would get their role and belong to **no team**.
- **Override detection for the `kinetix_*` Inertia props** — in `local` only,
  Kinetix logs a warning when a response no longer carries its own shared prop
  (i.e. `HandleInertiaRequests::share()` returned a `kinetix_permissions` key,
  which `array_merge` puts last). Previously this silently turned every `can()`
  into `false` with nothing in the logs. Nothing is registered in production.

### Changed

- The permissions/membership **skills** now open with a "Common integration
  mistakes" section (wrong route prefix, null `attach_member`, overwritten share,
  teamless roles) instead of only describing the API, and spell out `{prefix}`
  wherever endpoints are listed.
- **Docs**: `permissions.md` and `membership.md` now open with the endpoint-prefix
  contract; §3 became "Gate bypasses (super admin & team owners)" with a Team
  owners section; the frontend section warns against redefining the shared props;
  `installation.md` documents `kinetix:routes` and the skills publish.

## [0.117.0] - 2026-07-25

### Added

- **Product Tours module** — backend-declared, permission-aware guided tours
  rendered by driver.js with a shadcn theme:
  - **Declare per module**: `KinetixTours::tour('posts')->page('Kinetix/Posts/Index')
    ->permission('posts.viewAny')->steps([TourStep::make('[data-tour=create]')
    ->title(__('…'))])` from any service provider. Tours travel on the
    `kinetix_tours` Inertia share, permission-filtered server-side (a denied
    user never receives the steps), with `page`/`url` wildcard matching and
    `auto(false)` for manual-only tours.
  - **One global host**: `<KinetixTours />` mounted in the layout auto-starts
    the unseen matching tour on every Inertia navigation. driver.js (~5 kB,
    MIT, the most popular actively-maintained tour engine) is an opt-in host
    dependency (`kinetix:install --tours`), lazy-imported so apps without it
    ship nothing extra; the published `kinetix.css` themes its popover,
    buttons, progress and arrows to the shadcn tokens (light + dark).
  - **Seen-state drivers**: `local` (browser localStorage, default, zero
    backend) or `database` (per-user `kinetix_tour_state` table + team-aware
    idempotent seen/reset endpoints — survives devices; publish
    `kinetix-tours-migrations`). Both finishing and dismissing count as seen.
  - **Pinia store** (`useKinetixToursStore`) for manual control: `start(id)`
    (replay buttons, help menus — ignores seen state), `hasSeen`/`markSeen`/
    `reset`, reactive share state.
  - Four `tour_*` strings in all seven locales; `docs/tours.md`; the legacy
    dependency-free `useKinetixTour`/`<KinetixTour>` stays for the lightweight
    hand-mounted case (onboarding docs point at the new module).

## [0.116.1] - 2026-07-25

### Fixed

- **`kinetix:upgrade` now recompiles the vue-i18n bundle the app actually
  imports.** The hook ran a bare `vue-i18n:generate`, so apps that compile
  per-locale files (`--multi-locales`) got the single-file bundle they don't
  import regenerated (an orphan artifact) while their imported files went
  stale — raw `kinetix.*` keys appearing in the UI after every composer
  update. The call now forwards `kinetix.translations.vue_i18n_options`;
  multi-locale apps set `['--multi-locales' => true]` there (documented in
  installation.md).
- **Published copies are protected from the host's formatters.**
  `kinetix:install` now appends the vendor-managed publish paths to
  `.prettierignore` (idempotent, preserves existing entries) and prints the
  eslint flat-config `ignores` equivalent — the Laravel Vue starter kit's
  default `prettier --write resources/` reformatted the published files, which
  the upgrade hook then overwrote, churning the diff on every `format`/update
  cycle. Existing installs: re-run `kinetix:install` or add the block by hand
  (see installation.md).

## [0.116.0] - 2026-07-24

### Added

- **Permission shapes beyond CRUD, end to end.** The catalog and the role
  editor now map the three real-world shapes without ever growing the matrix
  header:
  - `Feature::access()` — access-only modules (dashboards, report sections):
    a single `{feature}.access` ability rendered as the matrix's first
    canonical column.
  - **Custom in-module abilities** (e.g. `employees.viewSalary`) are never
    columns: they render inside their module's expandable row (an `n/m` chip
    next to the module name) with their full labels — the header vocabulary is
    fixed (`access` + the CRUD lifecycle) no matter how large the catalog gets.
  - `Feature::group('HR')` — optional titled sections in the editor matrix and
    the roles overview (`PermissionFeatureData` now ships `group`).
- **`->can('feature.ability')` server-side field gating (published trait).**
  Attachable to form fields, infolist entries, table columns and actions:
  evaluated at serialization against the authenticated user (never deferred,
  unlike record-bound `authorize()`). A denied component is stripped from the
  form schema, its validation rules AND submitted state (smuggled values never
  reach the model), the infolist, and the table's headers, row payloads,
  sorting and inline-edit allowlist — gated data never leaves the server.
  Completes the field-level story: declare `->ability('viewSalary', …)`,
  assign it in the matrix, enforce with one method on the schema.
- New `access` / `role_custom_abilities` strings in all seven locales.

### Documentation

- permissions.md: "Mapping real-world permission shapes" (CRUD / access-only /
  custom + field-level `->can()` with the can-vs-authorize distinction) and an
  updated role-matrix description.

## [0.115.1] - 2026-07-24

### Security

- **Role management is now tenant-isolated under spatie team scoping.** The
  endpoints previously queried roles with no team or guard filter:
  - the listing returned **every team's roles** (names, permissions, member
    counts — a cross-tenant information leak);
  - `update`/`destroy` resolved any role by id, so a team-A `roles.manage`
    holder could modify or delete **team B's roles**;
  - `store` used `findOrCreate`, so "creating" a role whose name already
    existed — including a GLOBAL seeded role like `admin` — silently re-synced
    that role's permissions (cross-team sabotage/escalation vector).

  All queries are now scoped to the configured guard plus the current team and
  global (team-NULL) roles; foreign roles 404 (existence never leaks); global
  roles are modifiable only by a super-admin (they apply to every team); and
  creating or renaming to an existing in-scope name is a 422 validation error,
  never a silent takeover. `RoleData` now exposes `isGlobal`, and the role UIs
  badge global roles and hide/disable their edit controls for non-super-admins.
- **Permission team context follows the URL's team.** `SetPermissionsTeam` now
  resolves the active team through the canonical `KinetixTeams::currentTeamKey()`
  resolver — the `{current_team}` route segment translated via the user's teams
  relation (membership check included: a foreign segment 404s) — instead of
  only the user's sticky `current_team_id` column, keeping the authorization
  context consistent with the data each request serves.

### Changed

- **`kinetix_permissions` share is cheaper per request.** The dynamic-grant
  Gate sweep (which surfaces `Gate::before` grants like team owners) is skipped
  entirely for super-admins (`useKinetixCan` short-circuits on the flag) and
  skips abilities already present as stored rows.

### Documentation

- permissions.md: new "Production: caching & deploys" section (immediate cache
  invalidation on edits, shared cache store for multi-server, deploy checklist,
  per-request cost, Octane notes) and a "Team-scoped vs global roles" matrix
  documenting the hybrid model and who may modify what.

### Fixed

- **Permission-matrix column headers no longer leak feature-specific labels
  (published).** The role editor matrix shares its ability columns across
  every feature, but each column's header came from the FIRST feature that
  declared the key — so a feature with customized labels (e.g. members'
  "Change member role" for `update`) renamed the shared column above every
  other row. Canonical CRUD keys (`viewAny` → `forceDelete`) now always render
  generic translated headers ("View all", "Edit", …); only custom abilities
  keep their own label. Adds `view_any` / `delete_any` strings to all seven
  locales.

## [0.115.0] - 2026-07-23

### Added

- **Help Center module** — an in-app user manual rendered from markdown files
  the host app owns (`kinetix.help.path`, default `resources/help`), ported
  from an app-specific implementation and made universal:
  - **Authoring**: one `.md` per article; flat front matter
    (`title`/`group`/`icon`/`order`/`permission`); locale variants via
    `{slug}.{locale}.md` with regional fallback (`pt_BR` → `pt` → base).
  - **Permission-aware**: `permission:` hides an article server-side (index,
    search AND a 404 on direct access — existence never leaks), and
    `<!-- kinetix:can ability -->…<!-- /kinetix:can -->` strips blocks inside
    an article for users the Gate denies. Rendering is hardened
    (`html_input=strip`, `allow_unsafe_links=false`); rendered HTML is never
    cached (metadata-only cache, `kinetix.help.cache`).
  - **Screenshots via Playwright** (documented host dependency):
    `php artisan kinetix:help-screenshots` drives the publishable runner
    (`--tag=kinetix-help-screenshots`) against the running app — configurable
    login selectors/viewport/pages, `{team}` placeholder, credentials via env —
    and uploads the PNGs to a **configurable disk** (local default, S3, any
    Laravel disk; private disks work because images always stream through the
    authenticated endpoint, with a committed-to-repo local fallback).
  - **Frontend (published)**: `<KinetixHelpCenter>` (grouped cards or list
    view with a toggle, debounced server-side search over titles and bodies)
    and `<KinetixHelpArticle>` (sanitized HTML, "on this page" TOC with scroll
    tracking, prev/next, Inertia-routed internal links), plus
    `useKinetixHelp()` / `useKinetixHelpToc()`.
  - **Spotlight**: help articles surface in the global command palette
    (permission-filtered) when both modules are enabled.
  - **Scaffold**: `kinetix:make-help-page` writes the two host pages, seeds a
    sample article and prints team-aware routes.
  - Eleven new translatable strings across all seven locales;
    `docs/help-center.md` covers authoring, permissions, storage drivers and
    Playwright troubleshooting.

## [0.114.0] - 2026-07-22

### Added

- **Plan-gating suite for the frontend (published).** The billable's current
  plan (slug/name + features JSON) is now shared as the `kinetix_billing`
  Inertia prop when billing is enabled, and two new primitives consume it:
  `useKinetixPlan()` (`plan` / `onPlan` / `featureValue` / `canUseFeature` /
  `hasReachedLimit` / `remaining` — same dot-path semantics as the backend) and
  `<KinetixPlanFeature>` (the billing twin of `<KinetixCan>`: a capability mode
  `feature="capabilities.api"` and a usage-limit mode
  `limit="usage.products" :count="n"`, both with a `#denied` slot and a
  `remaining` slot prop). Display gating only — the server still enforces via
  `plan.feature` middleware / `HasPlan` checks.
- **`Plan::remainingLimit()` / `HasPlan::remainingPlanLimit()`** — units left
  before a usage limit is reached (floored at zero; `null` = unlimited),
  rounding out `featureValue` / `canUseFeature` / `hasReachedLimit`.

### Documentation

- billing.md: documented the remaining-limit helpers, a server-side
  enforce-before-write example for usage limits, and a new "Gate the UI by
  plan (frontend)" section (shared prop, composable, declarative component,
  and the display-gating-only warning).

## [0.113.0] - 2026-07-21

### Added

- **`KinetixRolesOverview` (published)** — an at-a-glance roles & permissions
  audit view modeled after the "permission matrix" pattern: role cards (member
  counts + the modules each role touches) plus a READ-ONLY matrix with one row
  per module and one column per role. Cells show a check (all abilities), a
  `granted/total` badge (partial) or an em-dash (none); the header row and
  module column stay sticky. Create/edit reuse the same editor modal as
  `KinetixRoleMatrix` (a card's pencil or a role's column header opens it).
- **`kinetix:make-roles-page`** — scaffolds
  `resources/js/pages/Kinetix/Roles/Index.vue` mounting the overview behind
  `roles.manage` (with a denied fallback) and prints the (team-aware) route to
  register. `--force` overwrites.
- New translatable strings (`permission_matrix`, `role_matrix_full`,
  `role_matrix_partial`, `role_matrix_none`, `role_more_features`) in all seven
  locales.

### Changed

- **Sticky role-matrix editor (published).** The `KinetixRoleMatrix` editor
  modal now scrolls the matrix inside its own container with a sticky header
  row AND a sticky module column, so the ability being granted and its module
  stay visible on long catalogs; the name field and Save/Cancel footer no
  longer scroll away. The module row-toggle is a real button
  (keyboard/screen-reader accessible) and each cell carries `aria-pressed` +
  an `aria-label`. The editor dialog and delete confirmation are extracted to
  reusable `Roles/KinetixRoleEditorModal.vue` / `Roles/KinetixRoleDeleteDialog.vue`
  (shared with the new overview) — re-publish components to pick this up.

### Fixed

- **ESLint runs again.** `defineConfigWithVueTs` scans the repo for `.vue`
  files at config load; its fast-glob call converts the config's `ignores` to
  absolute patterns, which don't prune traversal — so it followed testbench's
  circular `vendor/orchestra/testbench-core/laravel/vendor` symlink and every
  eslint invocation crashed with ELOOP before linting anything. The lint npm
  scripts now remove that symlink defensively (`prelint`/`prelint:check` —
  testbench works without it; the full PHP suite passes), the `ignores`
  patterns use explicit `/**` globs, and the ~47 style errors that accumulated
  while the linter was broken are fixed (import order, top-level type imports,
  padding lines, two unused symbols).

## [0.112.1] - 2026-07-21

### Fixed

- **Team-scoped permission context on tables / imports / uploads /
  notifications endpoints.** With `kinetix.teams` on, these four route groups
  were nested under `{current_team}` but did not carry the
  `kinetix.permissions.team` bridge middleware (unlike the other 27 Kinetix
  groups), so policy checks on them — e.g. the simple-resource record-modal
  endpoint's `view`/`create`/`update`/`delete` authorization — evaluated
  without spatie's team context and denied users whose roles are team-scoped.
  All team-prefixed groups now attach the bridge.

### Documentation

- Teams audit notes: export endpoints are the documented exception (registered
  without the `{current_team}` prefix because their URLs are token-signed and
  the download link is built inside queued jobs); billing keeps its own
  per-module team flag (`kinetix.billing.teams`) and `{team}` parameter, and is
  the only module honoring `kinetix.tenancy.subdomain`.

## [0.112.0] - 2026-07-21

### Added

- **`Resource::getUrl(operation, ?record)` (public).** The team-aware URL
  resolver behind breadcrumbs/redirects is now public API: it builds the URL for
  a resource operation (`index` / `create` / `store` / `show` / `edit` /
  `update` / `destroy`), auto-filling the record's route key and the
  `{current_team}` segment when the route expects one. Controllers use it to
  hand ready-made URLs to the Vue pages.

### Fixed

- **Full-mode scaffold no longer breaks under team-scoped routes.** The Vue
  pages generated by `kinetix:make-resource` hardcoded `/{slug}` URLs
  (`router.post('/posts')`, `router.put('/posts/${id}')`, Cancel →
  `router.get('/posts')`), which 404'd when the resource's routes were nested
  under the `{current_team}` prefix. The generated controller now passes
  server-resolved `storeUrl` / `updateUrl` / `cancelUrl` props (via
  `Resource::getUrl()`) and the Create/Edit pages consume them — the same page
  output works with and without teams. The Edit page's `recordId` prop is gone
  (`updateUrl` replaces it); re-scaffold or update customized pages accordingly.
- **Simple-resource modals no longer show stale validation errors (published).**
  Cancelling a create/edit modal after a failed submit and reopening it rendered
  the old error bag on a pristine form (`KinetixForm` reads `page.props.errors`,
  which Inertia only replaces on the next visit). `useKinetixRecordModals` now
  clears leftover errors when a modal opens, and closing the form/view modal
  also drops the active form/infolist DTO so nothing stale flashes on reopen.

### Tests

- Team-mode coverage for the record-modal endpoint (`RecordModalCrudTeamsTest`):
  routes registered under `{current_team}`, cross-team resolve/update/destroy
  rejected via the resource's scoped query, and `team_id` stamped on create —
  complementing the existing non-team `RecordModalCrudTest`.
- `ResourceUrlTest` for `Resource::getUrl()` (with/without the team segment +
  unregistered-route fallback) and scaffold assertions that the generated pages
  submit/cancel through the server-resolved props in both team and non-team
  modes.

## [0.111.3] - 2026-07-20

### Fixed

- **Role management no longer 403s a team owner whose permissions come from
  `Gate::before` (published).** The anti-escalation guard `assertCanGrant()` only
  inspected Spatie's **stored** rows (`getAllPermissions()`), so a team owner
  granted abilities dynamically via a host `Gate::before` (e.g.
  `$user->ownsTeam($team)`) — with no `model_has_permissions`/`role_has_permissions`
  rows — was wrongly refused ("You cannot grant permissions you do not hold")
  even though the Gate authorizes them. The guard now resolves "held" through the
  **Gate** (`Gate::forUser($user)->allows($permission)`), unifying with how all
  Kinetix enforcement already works (stored perms + any `Gate::before` bypass).
- **Frontend `can()` map now reflects `Gate::before` grants (published).** The
  `kinetix_permissions` Inertia prop built the SPA capability map purely from
  stored rows, so the same owner saw an **empty** map and the UI hid every
  permission-gated control the server would allow. The shared `permissions` now
  also includes any registered ability the Gate grants for the user (owner /
  super-admin / any host dynamic grant), matching server-side authorization. Prop
  shape is unchanged (no frontend change needed).

### Documentation

- **Localizing schema labels.** Documented that any display string a developer
  sets on a Kinetix builder in PHP (`->label()`, `->heading()`, `->placeholder()`,
  action labels, select/filter option labels, section/tab headings,
  `getNavigationLabel()`, …) must go through Laravel's `__()` helper to be
  translatable — with examples. Added to `docs/locale.md`, the `kinetix-locale`
  boost skill, the umbrella `kinetix-development` skill, and the tables/forms/
  resources/infolists boost skills so AI-assisted code is localizable by default.

- **Starter-kit wide-table fix.** Documented how to stop a wide table overflowing
  the viewport in the Laravel Vue starter kit: a single global CSS rule
  (`[data-slot='sidebar-inset'] { min-width: 0 }`, the most general option since
  `data-slot` is a stable shadcn contract), plus per-component patches
  (`SidebarInset.vue` / `AppContent.vue`) and the generic per-page `min-w-0`
  wrapper. See `docs/starter-kit.md` (linked from `docs/tables.md`).

## [0.111.1] - 2026-07-17

### Fixed

- **Wide tables no longer overflow the viewport in flex layouts (published).** A
  flex item defaults to `min-width: auto` and won't shrink below its content, so
  a many-column table grew its column and pushed the page instead of scrolling
  inside its card. `KinetixTable`'s card now carries `min-w-0 max-w-full`, and the
  scaffolded page wrapper adds `min-w-0`, so a too-wide table scrolls locally
  (`overflow-x-auto`) as intended. Docs note the same `min-w-0` requirement for
  custom layouts (a common starter-kit gotcha).

## [0.111.0] - 2026-07-17

### Changed

- **Scaffold polish (published).** `kinetix:make-resource` now:
  - Wraps every generated page (Index, Create, Edit) in a consistent
    `flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4` container
    so pages fill the layout cleanly.
  - **Groups row actions into a shadcn-style "⋯" dropdown** by default
    (`ActionGroup::make([...])`) for both simple and full resources.

### Fixed

- **`ActionGroup` no longer renders an empty dropdown.** When every child action
  is hidden/unauthorized for a record, the group is dropped from that row's
  payload instead of showing an empty "⋯" button.

## [0.110.0] - 2026-07-17

### Added

- **`Action::route(string $name, array $params = [], string $method = 'get')`** —
  the intuitive way to wire CRUD actions to named routes. It resolves the URL by
  convention (per-record when the route has a record parameter, e.g.
  `posts.edit`; once for a record-less route, e.g. `posts.create`), fills the
  `{current_team}` segment, and — crucially — **auto-hides the action when the
  route isn't registered** (`Route::has`), so an unwired action never renders as
  a dead button. A non-`get` method (e.g. `'delete'`) performs an Inertia visit.

### Changed

- **Generated resources now declare their table actions on the resource
  (published).** `kinetix:make-resource` writes View/Edit/Delete/Create (and
  `recordModals`/`reorderable` for `--simple`) into the resource's `table()`
  instead of the controller — a single, discoverable source of truth. Controllers
  are thin (`Resource::table(Table::make(...))->toArray()`). Full-mode actions use
  the new `->route()` (self-hiding); simple-mode use `->modal()`. The full-mode
  `Index.vue` no longer hard-codes a "New" button — it comes from the table's
  `CreateAction`, which self-hides until `posts.create` is registered.

  This fixes the confusing "buttons render but do nothing / aren't in the table
  config" behaviour: actions are visible in the resource and only appear once
  their routes exist.

## [0.109.0] - 2026-07-17

### Added

- **Full resources now scaffold a `show` page (published).** `kinetix:make-resource`
  (multi-page mode) generates a `show()` controller method, a per-row `ViewAction`
  on the index table, and a `Show.vue` that pairs the resource's `infolist()` with
  a `KinetixPageHeader` carrying Edit / Delete actions (top-right) for quick
  redirects. The resource is scaffolded with an `infolist()` for this.
- **Configurable post-save redirect (published).** New resource hooks
  `Resource::getRedirectUrlAfterCreate(Model)` (default: index) and
  `getRedirectUrlAfterSave(Model)` (default: **stay on the edit page**) decide
  where full-page create/update land. Override with
  `static::resolveHref('index' | 'edit' | 'show', $record)`. Generated `store()`
  captures the created record so it can redirect to it.

### Changed

- **Simple-resource create/edit/view modals are teleported to `<body>` (published).**
  `KinetixTable` now renders its record modals through `<Teleport to="body">`
  (SSR-guarded), so the overlay is never clipped or mis-stacked by the table's
  own container — matching `KinetixConfirmModal`.

## [0.108.1] - 2026-07-17

### Fixed

- **`vue-tsc` type error in the record-modals wiring (published).** The
  `useKinetixRecordModals` composable typed the active form/infolist state as
  `Record<string, any>`, which did not satisfy the `KinetixForm` / `KinetixInfolist`
  prop types (`TS2739`) when `KinetixTable` is type-checked. The form ref is now
  `any` (matching the rest of the dynamic form layer) and the infolist ref is
  typed `KinetixInfolistData`.

## [0.108.0] - 2026-07-17

### Added

- **Kinetix-owned in-table modal CRUD for simple resources (published).** A
  `--simple` resource page is now literally `<KinetixTable :table>` and can
  create, edit, view (via the resource's `infolist()`), reorder and delete —
  no modal markup or submit wiring in the page. Opt in on the table with
  `Table::recordModals(PostResource::class)` and flag actions with
  `->modal('create'|'edit'|'view'|'delete')`; `KinetixTable` hosts the form and
  infolist modals and runs CRUD through a new signed record endpoint
  (`_kinetix/tables/record` + `/resolve`), guarded by an encrypted
  `{ model, resource }` token and the model's policy (view/create/update/delete,
  enforced when a policy exists — same rule as cell-update/kanban-move).
- **Fresh-record-on-edit (published).** Opening the edit modal fetches a fresh
  copy of the record from the server by default, so a concurrent change is never
  silently overwritten. Configure globally with `kinetix.tables.record_source`
  (`server` default, or `row` to prefill from the already-loaded row) or per
  table via `->recordModals(Resource::class, source: 'row')`.
- **`Action::modal(string $mode)`** — opens an in-table record modal instead of
  navigating/dispatching (serialized as `modal` on the action DTO).
- **Resource hooks `getEloquentQuery()` and `mutateFormDataBeforeSave()`** — the
  base query is reused by the generated index page and the modal endpoint (so
  team scoping applies to CRUD), and submitted data can be mutated per operation
  (e.g. stamping `team_id`).
- **`kinetix:make-resource --reorderable`** — adds `->reorderable('sort_order')`
  to a generated table (drag handles + persisted order).
- `record_created` / `record_updated` / `record_deleted` translation strings
  across all 7 shipped locales **(published)**.

### Changed

- **`kinetix:make-resource --simple` scaffold (published).** The generated
  controller is now index-only (CRUD is handled by Kinetix); the generated
  `Index.vue` is just `<KinetixTable :table>`; the resource gains a scaffolded
  `infolist()` (drives the View modal) and, with `--team`, `getEloquentQuery()` /
  `mutateFormDataBeforeSave()` overrides. The previous event-dispatch + in-page
  modal approach (v0.107.0) is replaced. Regenerate with
  `php artisan kinetix:make-resource {Model} --simple` (review your routes: a
  simple resource now needs only its `index` GET route).

## [0.107.1] - 2026-07-17

### Fixed

- **Confidential keyring migration is now publishable.** The
  `create_kinetix_confidential_keys_table` migration shipped in the package but
  was never registered under a publish tag, so `php artisan vendor:publish
  --tag=kinetix-confidential-migrations` published nothing and the keyring table
  could not be created through the documented flow. The tag is now registered
  (mirroring the other feature migrations).

### Added

- `kinetix.tables.number_locale` config key — locale used to format numeric
  column summaries (Sum/Average/Range). Defaults to the app locale.

### Documentation

- Doc-vs-code sweep across all feature guides: corrected the Confidential
  install step to publish the migration first; fixed a nonexistent
  `TextColumn::make(...)->numeric()` call and a stale "relationship sorting is
  skipped" note in Tables; corrected the Reports Center install step to publish
  its migrations; fixed `<KinetixInfolist :schema>` → `:infolist`; standardized
  all component import paths to the published `@/components/kinetix/…` location;
  aligned documented `enabled` defaults with the shipped (opt-in `false`)
  config; and matched the Widgets CSS custom-property names
  (`--grid-columns-*`) to the real component.

## [0.107.0] - 2026-07-17

### Fixed

- **Client-side (TanStack) tables now render toolbar/header actions.**
  `KinetixDataTable` dropped `table.toolbarActions` entirely — a `->clientSide()`
  table showed no header action buttons. They now render (with the same
  in-flight `:disabled` guard as the server-driven table). **(published)**

### Changed

- **Per-row actions carry their record into the action event.** When a record
  action is clicked, the table passes the row to `executeAction` as extra data,
  so a `dispatchEvent` action's `CustomEvent.detail.record` is the row (and an
  `inertiaVisit`/`httpRequest` body includes it). `useActionConfirmation`'s
  `requestAction(action, extraData)` threads it through the confirm flow.
  **(published)**
- **`make-resource --simple` scaffolds an event-driven modal CRUD.** Create/Edit
  are now table actions that **dispatch** browser events (`kinetix:{slug}-create`
  / `-edit`); the generated `Index.vue` listens and opens its in-page modal —
  create empty, edit **prefilled from the clicked row** (instant, client-side).
  Add your own listeners for custom behaviour instead. Delete stays a
  confirm → server `DELETE`. (Replaces the v0.106.0 `?edit` partial-visit
  approach.)

## [0.106.1] - 2026-07-17

### Fixed

- **`kinetix:make-resource` (full multi-page mode) now scaffolds per-row
  Edit/Delete actions too.** Complements the v0.106.0 `--simple` fix: the
  generated controller's index table gains an `EditAction` (navigates to the
  edit page via `route('{prefix}.edit', $record)`) and a `DeleteAction`
  (confirm → `DELETE route('{prefix}.destroy', $record)`). Previously the
  full-mode table rendered no row actions at all. Regenerate to pick this up.

## [0.106.0] - 2026-07-17

### Fixed

- **`kinetix:make-resource --simple` now scaffolds a working modal CRUD.** The
  flag has always documented per-row Edit/Delete plus a toolbar Create in modals,
  but the generated controller attached **no** row actions and the Vue page's
  `openEditModal` was dead code (never wired) — so only Create worked. The
  generated controller now attaches per-row `EditAction`/`DeleteAction`: **Edit**
  re-renders the index with `?edit={id}` (an Inertia partial visit that preserves
  scroll/state) so the modal opens prefilled with the **raw** record, and
  **Delete** confirms then issues the `DELETE`. The controller passes an
  `editRecord` prop and `Index.vue` opens the editor from it (closing clears the
  `?edit` param). `index()` now receives a `Request` even without `--team`.
  Regenerate simple resources to pick this up.

## [0.105.0] - 2026-07-16

### Fixed

- **Actions can no longer be double-submitted, and confirmations wait for the
  response.** `executeAction` is now async (awaiting the background HTTP request
  and the Inertia visit), and `useActionConfirmation` tracks a `processing`
  state: while an action is in flight repeat clicks are ignored, action buttons
  disable, and the confirmation modal stays open — disabled, with a spinner —
  until the request resolves, instead of firing and closing instantly. Applies to
  record / toolbar / footer / bulk table actions, the action dropdown, page
  header, infolist and calendar-event actions. **(published)**
- **Failed background actions surface the server's error message** (i18n) rather
  than a hardcoded English string. New `action_failed` translation key across all
  locales.

### Security

- **New-tab actions open with `noopener,noreferrer`.** `window.open(...)` no longer
  lets the opened page reach back through `window.opener` (reverse tabnabbing).
  **(published)**

### Changed

- **`KinetixConfirmModal` gains a `processing` prop** and no longer self-closes on
  confirm — the parent closes it once its (possibly async) handler resolves, so the
  modal can show a pending state. All in-package usages are updated; if you render
  `<KinetixConfirmModal>` directly, close it from your `@confirm` handler (or bind
  `v-model:open`). **(published)**

## [0.104.1] - 2026-07-16

### Performance

- **`SuperAdmin::check` is memoized** per user × permissions-team-id, so the
  `Gate::before` super-admin bypass no longer reloads the user's roles on every
  authorization check when team scoping is on (a `WeakMap`, so it's request- and
  Octane-safe).
- **The `discoverResources` filesystem scan is cached** — the permission registry
  scans each configured directory once instead of on every `features()` call.
- **`KinetixRoleMatrix` precomputes** the feature → ability → permission map, so
  each grid cell is an O(1) lookup instead of a `.find()` over the feature's
  abilities. **(published)**
- **`kinetix:permissions:sync` fetches existing permissions in one query**
  (`whereIn`) instead of an `exists()` check per permission.

### Changed

- **New `useKinetixRoleEditor` composable** de-duplicates the save/delete
  orchestration (busy flags, success/error toasts, refetch) that
  `KinetixRoleManager` and `KinetixRoleMatrix` previously each re-implemented.
  **(published)**
- **`KinetixRoleManager` now shows each role's member count** — `usersCount` was
  already fetched from the endpoint but only displayed by `KinetixRoleMatrix`.
  **(published)**

## [0.104.0] - 2026-07-16

### Security

- **Role-management endpoints hardened against privilege escalation.** The
  `{prefix}/permissions/roles` endpoints (gated by `roles.manage`) gain three
  guardrails, all **bypassed for a super-admin**: (1) submitted permission keys
  are **allowlisted** against the registry (unknown keys → `422`); (2) a manager
  can only grant permissions **they themselves hold** (escalation → `403`); and
  (3) roles in the new `permissions.protected_roles` config (default: the
  super-admin role) can't be created/renamed/edited/deleted, while any change
  that would revoke the actor's own `roles.manage` is rolled back inside a
  transaction (`403`). **(published)**
  - **Behavior change:** previously any `roles.manage` holder could grant *any*
    registered permission. Give role administrators the seeded `admin` role (or a
    super-admin) so they can manage the full catalog.
  - New `permissions.protected_roles` config key (`null` protects just the
    super-admin role). New shared `Permissions\SuperAdmin` class centralizes the
    (team-aware) super-admin check used by the gate, the Inertia prop and the
    controller.

### Fixed

- **Super-admins no longer see permission-gated UI as denied.** A super-admin
  holds the *role*, not the individual permissions, so `useKinetixCan().can()`
  returned `false` for everything and hid buttons/sections the server actually
  authorized. The `kinetix_permissions` prop now carries `isSuperAdmin`, and
  `can()` / `canAny()` / `canAll()` / `<KinetixCan>` honor it — mirroring the
  server `Gate::before` bypass. `useKinetixCan` also exposes `isSuperAdmin` and
  now checks membership via a `Set` (O(1) per gate). **(published)**

## [0.103.1] - 2026-07-16

### Fixed

- **`vue-tsc` type errors** introduced in 0.103.0: the virtualization `measureRow`
  refs (`KinetixComments`, `KanbanColumn`) now accept Vue's element-ref union, the
  virtual-row `key` is narrowed off `@tanstack`'s `bigint`-capable type, the
  date-range filter value is cast to the calendar's shape, and `buildTableQuery`
  returns an Inertia-compatible payload type. `npx vue-tsc --noEmit` is clean.

### Added

- **`kinetix:install --tanstack`** installs the client-side table + list
  virtualization peers (`@tanstack/vue-table`, `@tanstack/vue-virtual`) in one
  step, mirroring `--charts`. Documented in the installation guide, and Comments /
  Kanban / Tables docs note the optional peer.

## [0.103.0] - 2026-07-16

### Changed

- **Internal refactor of the five largest components** into focused composables
  and per-domain subcomponents — no public API or markup change, same entry
  points and props. `KinetixTable` (1354→555), `KinetixEventCalendar` (940→498),
  `KinetixFormSchema` (813→287), `KinetixWizard` (721→290) and
  `KinetixIntegrationLogs` (588→136) now delegate to new files under
  `components/{Table,Calendar,Form,Wizard,IntegrationLogs}/` and new
  `useKinetix*` composables. The giant `v-if`/`v-else-if` field/filter/variant
  chains are replaced by **component maps** (O(1) type dispatch), and table rows
  gain `v-memo`. **(published)** — re-publish with
  `php artisan vendor:publish --tag=kinetix-components --force` to pick up the
  new subcomponents; existing published copies keep working unchanged.

### Performance

- **Charts are code-split.** `@unovis/vue` (a D3-sized dependency) is no longer
  eager-imported by `KinetixChartWidget`; the chart surface moved to
  `widgets/UnovisChartCanvas.vue`, async-loaded only when a chart with data
  renders — widget pages without charts never ship it. **(published)**

- **O(1) selection membership.** `KinetixRoleMatrix`, `KinetixPermissionMatrix`,
  `KinetixCheckboxList`, `KinetixTokenManager` and `KinetixWebhookManager` now
  test membership against a derived `Set` instead of an `array.includes()` scan
  per rendered cell (previously O(cells × selected) on large matrices/lists).
  **(published)**

- **Threshold-gated list virtualization.** New `useKinetixVirtualRows` composable
  (over `@tanstack/vue-virtual`) windows long lists; lists at or below the
  threshold (40) render in full, so there is no change or overhead for the common
  case. Applied to `KinetixComments` and `KinetixKanban` (per-column, via the new
  `Kanban/KanbanColumn.vue`). `@tanstack/vue-virtual` is a new **optional** peer
  dependency (`npm install @tanstack/vue-virtual`) — only loaded by components
  that virtualize. **(published)**

## [0.102.0] - 2026-07-15

### Added

- **Tables — sort by relationship columns.** `TextColumn::make('author.name')->sortable()`
  now sorts by the related column via a correlated subquery (no join, so no row
  duplication or column collision), supporting `BelongsTo` and `HasOne`. The
  sort key is **allowlisted** against the defined sortable columns, so an
  arbitrary query-string value can never reach `orderBy`. `sortable()` gains an
  optional custom resolver for multi-column / computed / aggregate sorts:
  `->sortable(using: fn (Builder $q, string $dir) => $q->orderBy(...))`.

- **Tables — client-side (TanStack) rendering mode.** `Table::make(...)->clientSide()`
  ships the full (capped, default 500) result set once and a TanStack Table
  engine performs search / sort / pagination entirely in the browser — no
  round-trip per interaction. Same PHP `Table` API and same `<KinetixTable>`
  entry point; the TanStack-backed renderer (`KinetixDataTable`) is **async-loaded
  only when a table opts in**, so the dependency is code-split off the
  server-driven path. `@tanstack/vue-table` is an **optional** peer dependency
  (`npm install @tanstack/vue-table`) — server-driven tables never load it. Best
  for small, fully-loadable datasets; server-only features (interactive filters,
  saved views, polling, reorder, bulk actions) remain in the default mode.
  Sorting/searching operate on the serialized display value. **(published)**
  - Docs: `docs/tables.md` (Rendering Modes; `sortable()`).

## [0.101.0] - 2026-07-15

### Added

- **Forms validation — FormRequest, Precognition & error focus.** Forms now
  validate through three interchangeable paths, all sharing one set of rules
  defined in the schema (never duplicated):
  - **FormRequest bridge** — `KinetixFormRequest` (extend it, implement
    `form()`) and the `ResolvesKinetixForm` trait (for requests that already
    extend another base) derive `rules()`/`messages()`/`attributes()` straight
    from the form. `dehydratedState()` returns the validated **and** dehydrated
    payload (runs `dehydrateStateUsing()` hooks, drops `saved(false)` fields).
    Namespace `Happones\Kinetix\Forms\Http`.
  - **Live validation (Precognition)** — opt in with `Form::precognitive()` /
    `Form::validationUrl()`; add Laravel's `HandlePrecognitiveRequests`
    middleware to the route and fields validate against the server as they
    change, reusing the FormRequest rules. Ships a **built-in** Precognition
    client (`useKinetixPrecognition`, on `fetch` + the XSRF cookie) — **zero
    new npm/Composer dependencies**.
  - **Custom messages & attributes** — `Field::validationMessages()` /
    `Field::validationAttribute()` and form-level `Form::messages()` /
    `Form::validationAttributes()`. Each field defaults its `:attribute` to its
    label, so validator messages read naturally out of the box.
  - **Error focus in Tabs & Wizards** — `KinetixForm` now reads Inertia's
    `errors` bag automatically (a controller `ValidationException` renders with
    no wiring) and hides a stale submit error the moment its field is edited.
    Tabs/Wizard steps holding an error are marked (destructive indicator,
    `aria-invalid`), the form switches/jumps to the first offending one (error
    steps stay navigable even under a `linear` wizard), and the first errored
    field is focused + scrolled into view — resolved recursively for any
    nesting via the shared `useKinetixFormErrors` helper. `KinetixWizard` gains
    an `errorSteps` prop. **(published)**
  - Docs: `docs/forms.md` §6, `docs/wizard.md`.

## [0.99.0] - 2026-07-12

### Added

- **Confidential Fields** — field-level encryption for Eloquent attributes,
  new namespace `Happones\Kinetix\Confidential` and config
  `kinetix.confidential`. Zero new Composer dependencies (`openssl_encrypt`/
  `decrypt` + Laravel's own `Crypt`/`Hash`/`Cache`/`Session`):
  - **`ConfidentialCast`** — add it to any string attribute's `casts()` and
    it's encrypted at rest and masked on read (`••••6789`) everywhere the
    attribute is accessed (Table, Infolist, Blade, API Resources, tinker) —
    masking is enforced in the cast itself, not in any UI layer, so a
    `->confidential()` flag on `TextColumn`/`TextEntry` is purely a cosmetic
    padlock icon. Per-field `ConfidentialCast::class.':<visible>,<head|tail>'`
    controls how many real characters stay visible. A column that already
    has plaintext data reads safely as-is (treated as legacy plaintext) and
    can be migrated in place with `php artisan kinetix:confidential:encrypt-existing`.
  - **Reveal gate**: `<KinetixConfidentialUnlock>` (zero props, mount once)
    prompts for the current password and opens a short, session-scoped
    reveal window (`reveal_ttl_minutes`, default 5) with a live countdown.
    Queued jobs (Reports Center exports included) have no active session,
    so confidential columns stay masked there by default — a deliberate
    safety property, not a gap.
  - **Key management**: one "current" Data Encryption Key at a time
    (`kinetix_confidential_keys`), rotated via
    `php artisan kinetix:confidential:rotate-key` — old keys stay retained
    so historical data keeps decrypting after rotation. The unwrapped key
    is cached (`key_cache_ttl_minutes`), so a KMS-backed key manager is
    called at most once per cache window, never once per row/field.
  - Ships a zero-dependency local key manager (wraps via the app's own
    `APP_KEY`); a host app can bind its own AWS/GCP KMS or Vault Transit
    driver by implementing the 2-method `KeyManager` interface — no cloud
    SDK is bundled.
  - Docs: `docs/confidential.md`. **(published)**

## [0.98.0] - 2026-07-12

### Added

- **Reports Center** — queued, DB-tracked CSV/XLSX report generation for
  large datasets, new namespace `Happones\Kinetix\ReportsCenter` and config
  `kinetix.reports_center` (deliberately separate from the existing
  email-only `kinetix.reports`/`Happones\Kinetix\Reports` — the two are
  independent, use either or both):
  - **`Report`** (`extends Exporter`, unchanged chunked `query()`/`getColumns()`/
    `format()`) — define one with `php artisan kinetix:make-report {name}`,
    dropped into `app/Kinetix/Reports` and **auto-discovered**, no manual
    registration required (register classes living elsewhere via
    `KinetixReportsCenter::register()`).
  - Every launch creates a `kinetix_report_runs` row tracked through
    `pending → running → completed|failed|cancelled`, with a live
    `processed_rows`/`percent` progress updated once per chunk (not per row).
  - **Cancellation** is cooperative — the queued job checks the run's status
    at the top of `handle()` and once per chunk, halting cleanly on its own;
    it does not (and cannot) kill the queue-worker process, so it works
    identically across every queue driver (database, Redis, SQS, Horizon).
  - **Retry** dispatches a fresh run with the same report/parameters; only a
    truly failed job (retries exhausted) marks `status=failed` — a transient
    error retried by Laravel's own `$tries` never surfaces as "Failed."
  - **Download** is disk-agnostic (same `KinetixDisk` config as the rest of
    Kinetix) and gated by a real, row-backed `expires_at`, pruned via
    `php artisan kinetix:report-runs:prune`.
  - **Scheduling & recurrence**: a `ReportSchedule` definition (`once`,
    `daily`, `weekly`, `monthly`) is dispatched by
    `php artisan kinetix:report-schedules:dispatch-due`, wired into the
    host's own scheduler (Kinetix doesn't own cron).
  - **`<KinetixReportLauncher>`** (pick a report type, "Run now"),
    **`<KinetixReportRunsTable>`** ("failed jobs"-style status/progress/
    download/cancel/retry table), **`<KinetixReportSchedules>`**
    (recurring/scheduled definitions + create/edit form), and
    **`<KinetixReportsCenter>`** (tabbed wrapper around all three) — all
    take zero props, self-fetching and polling on `kinetix.reports_center.poll`.
    Every standalone component is also valid mounted on its own.
  - Docs: `docs/reports-center.md`. **(published)**

## [0.97.0] - 2026-07-12

### Added

- **`<KinetixCookieConsent>`** — a shadcn-styled cookie consent bar (a simple
  accept/decline bar, comparable in scope to `spatie/laravel-cookie-consent`
  — not a granular per-category consent manager). No migration, route, or
  controller: config-only (`kinetix.cookie_consent`: `enabled`, `cookie_name`,
  `expiry_days`, `position` bottom|top, `policy_url`), shared to Inertia as
  `kinetix_cookie_consent`. Mount once with zero props; visibility is
  resolved entirely client-side (`useKinetixCookieConsent()` reads/writes a
  plain browser cookie, no server round-trip) — accepting/declining hides the
  bar for `expiry_days` days. Docs: `docs/cookie-consent.md`. Tests:
  `useKinetixCookieConsent.spec.ts`, `KinetixCookieConsent.spec.ts`.
  **(published)**

## [0.96.0] - 2026-07-12

### Added

- **Calendar: scroll-to-now and per-event actions**:
  - Switching the event calendar into `week`/`day` view — via the switcher,
    mounting directly in that view, or clicking "Today" while already
    there — now auto-scrolls the hourly grid so the current time stays in
    view (with a little context above it), instead of defaulting to the
    scrolled-to-midnight top edge. No-op when "now" falls outside
    `startHour`/`endHour`.
  - **`Calendar::eventActions(array $actions)`** — optional per-event actions
    (edit/delete/custom), resolved against each event's underlying record
    exactly like `Table::recordActions()`. Reuses the same `Action` builder
    and execution engine as Tables/PageHeader/Infolists
    (`inertiaVisit()`/`request()`/`dispatch()`/`requiresConfirmation()` via
    `KinetixConfirmModal`/`authorize()`/`visible()`/`hidden()`), rendered as
    small icon+label buttons identically in **both** the event-details modal
    and sheet. `CalendarEventData` gains `actions: ActionData[]` (defaults
    `[]` — omit for a purely read-only calendar).
  - Docs: `docs/calendar.md` gains a "Scroll-to-now" note and an "Event
    actions" section. Tests: `CalendarTest`, `KinetixEventCalendar.spec.ts`.
    **(published)**

## [0.95.0] - 2026-07-12

### Added

- **Widget grids: masonry layout, gap/dense customization, and two new
  self-polling widget types**:
  - **`WidgetsGrid::masonry(int|array $columns = 3)`** — a true
    column-balanced masonry layout: each widget occupies exactly one column
    (its `columnSpan` is ignored) and is placed into whichever column is
    currently shortest, eliminating the height gaps a plain CSS grid leaves
    between row-mates of different heights. Rendered by the new
    `<KinetixMasonryColumns>` (`resources/js/components/widgets/`), whose
    packing algorithm lives in a pure, unit-tested composable
    (`useMasonryColumns.ts`).
  - **`WidgetsGrid::gap(int|string|array $gap)`** (default `'1.5rem'`) and
    **`->dense(bool = true)`** (`grid-auto-flow: dense` on the standard
    `columnSpan` grid) — both accept the same responsive breakpoint-map shape
    as `columns()`.
  - **`QueueStatsWidget`** / **`HealthStatusWidget`** (types `queue-stats` /
    `health-status`) — thin `Widget` wrappers with no data payload that let
    the existing live `<KinetixQueueStats>`/`<KinetixHealthStatus>` panels be
    positioned (`columnSpan`/`sort`) and gated (`authorize()`) inside a grid;
    the Vue components keep self-polling exactly as they do standalone.
  - `KinetixStatsOverviewWidget`'s internal stat-card grid now uses a CSS
    `@container` query instead of viewport `@media` breakpoints, so it lays
    out correctly by its own rendered width (e.g. inside a narrow masonry
    column), not the browser viewport.
  - Docs: `docs/widgets.md` gains Masonry/Gap & Dense sections, a callout
    that a bare `columnSpan` doesn't auto-stack on mobile, and reference
    entries for the two new widget types. Tests: `WidgetsGridTest`,
    `useMasonryColumns.spec.ts`, `KinetixMasonryColumns.spec.ts`,
    `KinetixWidgetsGrid.spec.ts`, `QueueStatsWidgetTest`,
    `HealthStatusWidgetTest`. **(published)**

## [0.94.1] - 2026-07-12

### Fixed

- **`ProgressWidget::display()`/`caption()` reject `null`** — both properties
  are already nullable (`caption` has no default text), but the setters
  required a `string`, forcing a conditional `->caption(...)` call at the
  callsite whenever the value might be empty. Both now accept `?string`.

## [0.94.0] - 2026-07-12

### Added

- **`<KinetixTimezonePicker>`** — a new, standalone searchable timezone
  combobox (built on the same Reka Combobox primitives as
  `KinetixCombobox`), over every IANA zone the runtime supports
  (`Intl.supportedValuesOf('timeZone')` — no bundled zone list to maintain):
  - `regions` — restrict the list to specific IANA region prefixes (e.g.
    `['America', 'Europe']`).
  - `display: 'name' | 'offset' | 'both'` (default `both`) — `'offset'` shows
    just the UTC offset with no location name at all (e.g. `UTC-06:00`).
  - `groupByRegion` (default `true`) — a localized region heading
    (Africa/America/Asia/…) groups the dropdown; options are sorted by UTC
    offset then name throughout.
  - `showCurrentTime` — a live-updating clock next to the selected zone.
  - `clearable` — a clear (×) affordance.
  - Docs: `docs/timezone-picker.md` (new sidebar entry under *Interface &
    UX*). Tests: `KinetixTimezonePicker.spec.ts`. **(published)**

## [0.93.0] - 2026-07-12

### Added

- **Calendar: timezone-correct rendering, week/day views, and an event
  details popup**:
  - **Timezone support** — `Calendar::timezone(string|Closure|null)` (default
    `config('app.timezone')` via the new `Support\KinetixTimezone` helper).
    Events serialize as **absolute-instant ISO-8601 datetimes** instead of
    date-only strings, so the frontend can correctly re-render them under
    *any* timezone — the server's resolved default (`calendar.timezone`), or
    a client override via the new `timezone` prop (e.g. the viewer's own
    browser zone). `CalendarEventData` gains `allDay` (auto-detected: true
    when start/end fall exactly at midnight) and `description`.
  - **Month/week/day views** — opt-in via the new `views` prop (default
    `['month']`, unchanged single-view behavior); a switcher appears once
    more than one view is listed. Week/day render an hourly grid with an
    all-day banner and a current-time indicator, horizontally scrollable so
    7 day-columns never break page layout on narrow viewports.
    `startHour`/`endHour` restrict the visible hour range; `anchorDate`
    (ISO `Y-MM-DD`) sets the initial window (e.g. for deep-linking).
  - **Event details popup** — clicking an event opens a built-in modal
    (default) or a new standalone **`<KinetixSheet>`** primitive
    (`event-display="sheet"`, `sheet-side` top/right/bottom/left) showing
    the color, formatted date/time range, description, and a "View details"
    link when `url` is set. `@event-click` always fires regardless;
    `:show-event-details="false"` opts out of the built-in popup entirely.
  - `Calendar::description(string|Closure|null)` — optional per-event
    description shown in the details popup.
  - Docs: `docs/calendar.md` rewritten with dedicated sections + screenshots
    for timezones, views, and the event popup.
  - Tests: extended `CalendarTest` (PHP) and `KinetixEventCalendar.spec.ts`
    (timezone correctness across a day boundary, view switching, hourly
    positioning, popup modes), new `KinetixSheet.spec.ts`. **(published)**

### Changed

- **Breaking: `CalendarEventData.start`/`.end` format** — previously plain
  `Y-m-d` date strings, now full ISO-8601 datetimes with a UTC offset (e.g.
  `2026-06-15T09:00:00+00:00`). If you read `calendar.events[].start`/`.end`
  directly (outside the `<KinetixEventCalendar>` component itself), update
  that code to parse a datetime instead of a bare date. `CalendarData` also
  gains a required `timezone` field.

## [0.92.0] - 2026-07-11

### Added

- **`<KinetixWizard>` configurable step layout + per-step colors** (`stepper`
  variant):
  - **`stepLayout`** (`inline` (default) | `stacked` | `tooltip`, horizontal
    orientation only) — `inline` keeps the existing indicator+label side by
    side; `stacked` puts the indicator on top and the label/description
    centered below, always visible (unlike `inline`, never hidden on
    mobile); `tooltip` shows the indicator only, with the label/description
    revealed on hover/focus via a `reka-ui` Tooltip — the most compact
    option for 5-6+ steps on narrow viewports. PHP: `Wizard::make()
    ->stepLayout('stacked'|'tooltip')`.
  - **Per-step `color`** — `{ ..., color: 'success' }` on a `KinetixWizardStep`
    (or `Step::make($label)->color('success')` in PHP) accents that step's
    indicator once active/complete (`success|danger|warning|info|primary|
    gray`, the same tokens as everywhere else in Kinetix); upcoming steps
    always stay neutral regardless of their configured color.
  - Both apply to the standalone `<KinetixWizard>` and the PHP form-layout
    `Wizard`/`Step` builders (they share the same Vue core).
  - Docs: `docs/wizard.md` — every variant now has its own screenshot in its
    own section (`stepper`, vertical orientation, compact, `default`,
    `gradient`, `panels`, `vertical` rail, `simple`), plus new sections for
    step layout and per-step colors.
  - Tests: extended `KinetixWizard.spec.ts` (stepLayout rendering + per-step
    color) and `WizardLayoutTest` (PHP serialization). **(published)**

## [0.91.2] - 2026-07-11

### Fixed

- **`<KinetixUsageMeters>` blank-page crash when `usage` is missing** — if the
  billing controller ever fails to send `usage` (a transient Stripe outage, a
  one-off 500, a partial SSR render), the page rendered blank instead of
  degrading gracefully. `metrics` is now optional with a `[]` default
  (`withDefaults`), plus a `computed` that falls back to `[]` even if the prop
  arrives as an explicit `null` — so a server-side hiccup never blanks the
  billing page, it just hides the usage card. The `kinetix:make-billing`
  scaffold's `usage` prop is the same fix: optional with a `[]` default,
  removing the "Missing required prop" warning when the controller can't
  compute usage for a request. **(published)**

## [0.91.1] - 2026-07-11

### Fixed

- **`<KinetixWizard>` horizontal stepper overflow** — with 5-6+ steps and/or
  realistic (longer) labels, the `stepper` variant's horizontal indicator
  could overflow its container width, breaking the page's layout on mobile
  and tablet viewports (worst case: the `fullWidth: false` compact indicator
  ran its circles/connectors straight off the card on a phone-width screen).
  Fixed by wrapping the horizontal indicator in its own scroll container
  (`overflow-x-auto`) so it scrolls internally instead of breaking the page,
  and by letting step titles/descriptions `truncate` instead of forcing the
  row wider than its allotted flex space. Verified across mobile/tablet/
  desktop viewports for every variant (`stepper` horizontal/vertical,
  `default`, `gradient`, `panels`, `vertical`, `simple`) with 6 steps and
  long labels — no horizontal overflow in any combination. **(published)**

## [0.91.0] - 2026-07-10

### Added

- **Role/permission-gated Widgets, Form fields & Infolist entries** — hide any
  of the three from certain users based on a Laravel Gate ability, role
  check, or arbitrary closure, mirroring the authorization already available
  on `Action`:
  - `Widgets\Widget` gains `->visible(bool|Closure)`, `->hidden(bool|Closure)`
    and `->authorize(string|Closure|bool $ability, mixed $arguments = null)`
    (`Gate::allows($ability, $arguments)`), plus `shouldRender()`. A widget
    has no per-record pass, so unlike Actions a bare string ability is
    checked immediately — never deferred.
  - `WidgetsGrid::toArray()` now filters unauthorized/hidden widgets
    **before** computing any widget's data — a denied user never receives
    the widget's payload (not even hidden in the response), and its
    (possibly expensive) query never runs. This also fixes a pre-existing
    inefficiency where the sort comparator called each widget's `getData()`
    twice.
  - `Forms\Components\Component` and `Infolists\Components\Component` now
    both use the same `Support\Concerns\HasAuthorization` trait as `Action`,
    adding `->authorize(string $ability, mixed $subject = null)` alongside
    the existing `->visible()`/`->hidden()`. Without an explicit subject, a
    record-dependent ability defers to visible until a record exists (e.g.
    `create` forms) — exactly like `EditAction::make()->authorize('update')`.
    Unauthorized fields/entries are dropped from validation, hydration, and
    the serialized payload.
  - Docs: `docs/widgets.md` §6, `docs/forms.md` §5, `docs/infolists.md` §6.
  - Tests: `WidgetAuthorizationTest`, `FormFieldAuthorizationTest`,
    `InfolistEntryAuthorizationTest`.

## [0.90.0] - 2026-07-10

### Added

- **Metered-usage billing** — progress display for Stripe metered prices
  (API calls, seats, storage, …), with fully customizable measurement logic:
  - `Happones\Kinetix\Billing\UsageMetric` — fluent VO (`make($key)->label()
    ->used()->unit()->limit()->color(string|Closure)`) declared by the
    billable's `meteredUsage(?Plan $plan): array` method (hybrid detection —
    implementing `Billing\Contracts\ProvidesUsageMetrics` is optional).
  - `BillingManager::usage(): array<UsageMetricData>` resolves each metric
    against the billable's current plan: an explicit `limit()` wins,
    otherwise it falls back to the plan's `features.usage.{key}` (`null`
    either side = unlimited); percent is capped at 100 and `overLimit` flags
    when used ≥ limit; color defaults to threshold-based (`primary` under
    80%, `warning` 80–99%, `danger` at/over the limit) and is fully
    overridable per metric via a closure `(percent, overLimit) => color`.
    Wired into `BillingController::index()` as the `usage` prop.
  - `BillingManager::reportUsage(int $quantity = 1, ?string $priceId = null)`
    — the write-side companion, a guarded wrapper around Cashier's
    `SubscriptionItem::reportUsage()`/`reportUsageFor()`.
  - **`<KinetixUsageMeters>`** — a progress-bar card, one bar per metric;
    renders nothing when there's nothing to show, so it's safe to always
    mount alongside `KinetixSubscriptionStatus`. The scaffolded
    `kinetix:make-billing` page now wires it in.
  - New shared `statusFillClass()` in `useStatusColor.ts` (solid progress-bar
    fill, extracted from `KinetixProgressWidget`'s local map — both
    components now share it).
  - i18n `billing_usage_title` / `billing_usage_over_limit` across all seven
    shipped locales. **(published)**

## [0.89.0] - 2026-07-10

### Added

- **Chinese (zh), Japanese (ja) and Russian (ru) translations** — the full
  411-key catalog in each, with key parity enforced by the test suite.
  Shipped locales are now en, es, fr, pt, zh, ja, ru.
- **Selective translation publishing** — choose which locale catalogs
  `--tag=kinetix-translations` copies (and `kinetix:upgrade` refreshes) via
  `kinetix.translations.locales`: an array in the published config or a
  comma-separated `KINETIX_TRANSLATION_LOCALES=en,es` env value. Pick one,
  several or leave null/empty for all. English-only apps: `['en']` and no
  other catalog ever lands in `lang/`.

## [0.88.2] - 2026-07-10

### Added

- **`ProvidesPdfData` contract** — teach a model how to print itself:
  implement `toPdfData(): array` and pass the model directly to
  `KinetixPdf::render()/pdf()` (and `PdfTemplate::render()/pdf()`). The
  interface is optional (hybrid method detection, like Kinetix's other
  contracts); plain arrays keep working, and objects without the method throw
  a clear exception.

### Docs

- PDF Templates: the "Generating real documents" section now documents the
  model setup end-to-end (`ProvidesPdfData` implementation, hybrid detection,
  the data shape) instead of referencing an unexplained `toPdfData()` call.

## [0.88.1] - 2026-07-10

### Added

- **Webhook delivery-log knobs** — logging remains automatic with the module
  (both drivers, no extra setup; the logs table ships inside
  `kinetix-webhooks-migrations`), now tunable: `kinetix.webhooks.log_payloads`
  (default `true` — disable when events carry sensitive data) and
  `kinetix.webhooks.response_limit` (stored response cap, previously a
  hardcoded 1000 chars). Applied by the native job and the spatie bridge alike.

### Docs

- Webhooks: new "The delivery log" section (automatic logging, knobs, the
  cross-endpoint `GET {prefix}/webhooks/logs` feed in the endpoints table);
  Integration Logs page cross-references the knobs.

## [0.88.0] - 2026-07-10

### Added

- **PDF Templates** — configurable document formats, the Mailable of PDFs:
  - `PdfTemplate` classes (`static $key`, `fields()`, `sampleData()`,
    `paper()`, `logo()`) with a polished built-in generic document (header,
    parties, line items, totals, notes, signature, footer) and escape hatches
    (`html()` in PHP or a Blade `view()`).
  - Declarative `PdfField` knobs (color w/ palette, text, select, toggle,
    number) rendered as controls by **`<KinetixPdfTemplate>`** — live iframe
    preview of unsaved changes, save (persisted per template and per team when
    team scoping is on), reset-to-defaults and sample-PDF download.
  - `KinetixPdf` facade (`register`/`render`/`pdf`) for generating real
    documents with the stored settings applied.
  - Driver auto-detection: `spatie/laravel-pdf` → `barryvdh/laravel-dompdf` →
    `dompdf/dompdf` (configurable via `kinetix.pdf.driver`).
  - Endpoints gated by `viewKinetixPdf`; only declared fields are ever read
    from requests. Migration tag `kinetix-pdf-migrations`. **(published)**

## [0.87.0] - 2026-07-10

### Added

- **Auto-upgrade on composer update** (the Filament pattern) — new
  `kinetix:upgrade` command re-publishes the volatile published assets:
  components (+ composables, stores, TS types) and translations, recompiling
  the Vue i18n bundle when `laravel-vue-i18n-generator` is installed. It only
  refreshes targets the app has already published (never dumps files into apps
  that didn't adopt them). `kinetix:install` now registers
  `@php artisan kinetix:upgrade` in the host composer.json's
  `post-autoload-dump` (idempotent) — remove the hook if you maintain local
  edits to published files, which the upgrade overwrites.

## [0.86.1] - 2026-07-10

### Changed

- **`KinetixTokenManager` list polish** — each token now shows its creation
  date, the expiry badge sits next to the name, and the row layout stacks
  cleanly on mobile (meta line wraps; Revoke stays pinned top-right).
  **(published)**

## [0.86.0] - 2026-07-10

### Added

- **Token expiration** — the developer-token create form now includes an
  optional expiration date (shadcn calendar, future dates only): persisted via
  Sanctum's native `expires_at` (`createToken()`'s third argument, end of the
  chosen day), automatically rejected by the guard once past, surfaced in
  `TokenData.expiresAt` and badged in the token list (red "Expired" when
  past). Server validates `expires_at` as a future date (422 otherwise).
  **(published)**

## [0.85.0] - 2026-07-10

### Added

- **Integration Logs** — observability for SaaS integrations:
  - **API request logs** (opt-in `kinetix.api_logs`): the `kinetix.api-log`
    middleware logs method, path, status, duration, ip and the Sanctum token
    id/name of every request on your API group — written in `terminate()` (no
    request latency). Request/response bodies are opt-in, size-capped and
    sensitive keys are redacted. Feed at `GET {prefix}/api-logs`
    (gate `viewKinetixApiLogs`), migration tag `kinetix-api-logs-migrations`,
    retention via `kinetix:api-logs:prune`.
  - **Webhook delivery logs enriched** — `WebhookLogData` now carries the
    payload, response and endpoint name/URL; new cross-endpoint feed
    `GET {prefix}/webhooks/logs` (`webhooks.manage`) with result/search
    filters.
  - **`<KinetixIntegrationLogs>`** viewer — tabbed (webhook deliveries / API
    requests, or a single feed via `only`), success/failed filter, debounced
    search, pagination, and a detail modal with pretty-printed payloads and
    one-click webhook redelivery. **(published)**

## [0.84.0] - 2026-07-10

### Added

- **`KinetixRoleMatrix`** — a spreadsheet-style role manager: role cards with
  live member counts, and a modal editor whose table has one row per feature
  and one column per ability (canonical CRUD columns first, custom abilities
  appended, em-dash where a feature doesn't declare the ability; clicking a
  module name toggles its whole row). Same endpoints, `roles.manage` gating and
  team rules as `KinetixRoleManager`. **(published)**
- **`usersCount` on the roles endpoint** — `GET {prefix}/permissions/roles` now
  includes each role's member count (`withCount('users')`), surfaced in
  `RoleData` / the `KinetixRole` TS type.

### Changed

- **`KinetixMemberProvisioner` polish** — role options are headline-cased for
  display (`support-agent` → "Support Agent") while still submitting the raw
  slug, and the email field uses a `name@example.com` placeholder instead of
  repeating the label. **(published)**

## [0.83.0] - 2026-07-09

### Fixed

- **`{current_team}` resolved by route key + membership check** — team-aware
  scoping (saved views, tags, membership, presence channel) previously stored
  the raw route segment as the `team_id`, breaking hosts that route teams by
  slug/uuid (`Team::getRouteKeyName()`), and never verified membership. New
  `KinetixTeams::currentTeamKey()`: a bound model → its key; a scalar segment →
  resolved through the authenticated user's teams relation by route key name
  (**404 when the team isn't one of the user's** — membership is now enforced);
  hosts whose user model has no teams relation keep the legacy raw-segment
  behaviour (documented as host-side enforcement). The shared route prefix
  fallback now uses `currentTeam->getRouteKey()` for URLs instead of `->id`.

## [0.82.0] - 2026-07-09

### Changed

- **Per-module `teams` flags now inherit the global `kinetix.teams`** —
  tri-state: `null` (new default) inherits the global switch, `true`/`false`
  overrides per module. One line (`KINETIX_TEAMS_ENABLED=true`) now team-scopes
  the whole suite (permissions, membership, settings, webhooks, onboarding,
  wizards, features, activity, billing) instead of requiring nine flags; the
  per-module override remains for mixed setups (e.g. team-scoped app with
  personal billing). Resolution goes through the new
  `Support\KinetixTeams::enabledFor($module)` helper. **(published config)**
  > Upgrade note: previously published configs pin each flag to `false` —
  > re-publish `kinetix-config` (or change the flags you want inheriting to
  > `env('…_TEAMS')` with no default) to adopt inheritance.

## [0.81.0] - 2026-07-09

### Added

- **Import template download** — the import modal now offers a "Download
  template" link (on by default): a CSV whose header row is the importer's
  column **labels**, which auto-map when the filled file is uploaded back.
  Per-importer control via `protected bool $downloadableTemplate = false` and
  `protected ?string $templateFileName` (default: a studly of the importer
  class name — `ProductImporter.csv`). New
  `GET {prefix}/imports/template?importer={token}` endpoint
  (`kinetix.imports.template`, 404 when disabled); `ImportAction` carries the
  template filename in the `open-importer` dispatch, and `KinetixImporter`
  accepts it as the `template` prop for manual mounting. i18n
  `download_template` in en/es/fr/pt. **(published)**

## [0.80.1] - 2026-07-09

### Fixed

- **CI: `npm ci` EUSAGE failure** — the `esbuild` npm override (`^0.25.0`)
  conflicted with the new direct devDependency (`^0.25.12`), which makes npm's
  lockfile virtual-load fail with a misleading "can only install with an
  existing package-lock.json" error. The override now references the direct
  dependency (`"esbuild": "$esbuild"`, like `vite`).
- **CI: fatal in `SummarizerTest`** — the private `query()` test helper collides
  with a public `query()` on the newest `orchestra/testbench` `TestCase`;
  renamed to `productQuery()`.
- **CI/docs workflows: Node 20 → 22** (vite 8 line requires Node `^20.19 || >=22`).

## [0.80.0] - 2026-07-09

### Changed

- **`money()` is now locale-aware** on `TextColumn` and infolist `TextEntry`:
  values format through intl's `NumberFormatter::CURRENCY` in the application
  locale (`$1,234.50` in `en`, `1.234,50 €` in `de`) instead of the previous
  hardcoded `$1,234.56 USD`. New Filament-compatible signature
  `money(string $currency = 'USD', int $divideBy = 1, ?string $locale = null)` —
  `$divideBy` converts minor units (e.g. `100` when amounts are stored in
  cents) and the locale resolves from the argument → the column/entry
  `->locale()` → the app locale. Falls back to `CODE 1,234.50` when ext-intl
  is unavailable. Shared `FormatsMoney` concern, mirroring
  `Summarizer::money()`.

## [0.79.0] - 2026-07-09

### Added

- **Locale-aware date formatting** for table columns and infolist entries:
  `->date()` / `->dateTime()` with **no argument** now render through Carbon's
  `isoFormat()` in the application locale — "Jul 9, 2026" in `en`,
  "9 de jul. de 2026" in `es` — using the tokens from the new
  `config('kinetix.formats')` block (`date` default `ll`, `datetime` default
  `lll`). Passing a format string keeps the previous plain PHP `format()`
  behaviour. New `->isoDate($tokens?)` / `->isoDateTime($tokens?)`
  (Filament-compatible, explicit localized tokens) and `->locale('fr')`
  per-column/entry override. Shared `FormatsDates` concern powers both
  `TextColumn` and `TextEntry`.
- **`kinetix.formats` config block** — application-wide default date/datetime
  output formats (`KINETIX_DATE_FORMAT` / `KINETIX_DATETIME_FORMAT` env keys).

### Changed

- **Date/Month/Week/Range pickers, date filters and `NumberField` now default
  their locale to the application locale** (as BCP-47 — `es_MX` → `es-MX`)
  instead of the browser locale: calendars, weekday/month names and number
  formatting follow `app()->getLocale()` automatically. An explicit
  `->locale()` on the component still wins. **(published — no frontend change
  required; the value is serialized from the backend)**
- `->date()` / `->dateTime()` **without arguments** now produce localized
  output instead of the fixed English `M j, Y` / `M j, Y g:i a`. In `en` the
  rendering is nearly identical ("Jul 9, 2026"); in other locales it is now
  correctly translated. Call `->date('M j, Y')` to keep the exact old output.

## [0.78.0] - 2026-07-09

Production-hardening batch from real-app integration feedback.

### Fixed

- **Kanban + enum casts** — a `statusColumn` cast to a PHP enum no longer crashes
  `toData()` ("Object of class … could not be converted to string"): grouping
  stringifies `BackedEnum` → backing value / `UnitEnum` → case name, and moves
  re-cast the plain string. Numeric status keys are also stringified so strict
  status validation matches.
- **Kanban move authorization** — the `kanban-move` endpoint now authorizes the
  *record*, not just the board descriptor: when the model has a registered
  policy, every move checks `update` (or the ability named via
  `->authorizeMove()`); `->moveScope(['team_id' => $id])` seals tenant
  constraints into the encrypted descriptor and 404s lookups outside them.
  Previously any authenticated user could move records of other tenants by
  guessing ids.
- **Queued imports lose the tenant** — new `Importer::context(Request): array`
  hook, captured at dispatch and restored on the worker before any
  `importRow()` (`$this->context` / `getContext()`). Closes the multi-tenant
  leak where a queued importer had to infer the team.
- **Platform super-admin with spatie teams** — the `Gate::before` bypass now
  also honors a *teamless* role assignment (team `NULL`), so a platform
  super-admin keeps access inside every team. Previously `hasRole()` was
  team-scoped and the bypass silently vanished outside the assigning team.

### Added

- **`kinetix-permission-team-migrations`** publish tag — hybrid teams migration
  for spatie's permission tables: nullable `team_id` **outside** the pivot
  primary key (unique index instead), enabling global + team-scoped roles.
- **Config-mismatch warning** — a `Log::warning` at boot when
  `kinetix.permissions.teams` is true but spatie's `permission.teams` is false
  (the team id would be silently ignored).
- **`OnboardingStep::cta()` accepts a Closure** for the href, resolved per
  request with the authenticated user — required for team-scoped URLs, since
  steps register in `boot()`.
- **Filament-compatible API sugar**: `Column::state()` / `getStateUsing()`
  (derive the raw cell value), `SelectFilter::relationship($name, $titleColumn,
  ?Closure)` (options from the related model + `whereHas`, also on
  `MultiSelectFilter`), and `Component::columnSpanFull()`.
- **Filament contract cross-compatibility** — Enhanced Kinetix's contract validations (e.g. `HasLabel`, `HasColor`, `HasIcon`) to automatically detect and consume matching methods (`getLabel()`, `getColor()`, `getIcon()`) on enums or objects, ensuring 100% transparent compatibility with Filament's support contracts without requiring duplicate interface declarations or code changes. **(published)**
- **Searchable and Remote-Search Filters** — Added `searchable()` and `searchUsing()` support to `SelectFilter` (single select) and `MultiSelectFilter` (multiple select). Searchable select filters render as a `KinetixCombobox` and remote search filters lazily fetch results dynamically from the database. **(published)**
- **Reusable `KinetixCheckboxList` component** — Extracted a new reusable component `<KinetixCheckboxList>` with built-in support for local and remote searching (debounced, token-secured) and checked preservation (keeping selected options visible even when filtered out). Renders checkbox lists for both `checkbox-list` form fields and `multi-select` table filters. **(published)**
- **`ViewColumn` table column** — Added new `ViewColumn` (`view` type) that allows rendering table cells via custom Vue components registered in the host application with dynamic row-specific props. **(published)**
- **Custom cell slots** — Added dynamic scoped slots in `KinetixTable` (e.g. `cell-{column_name}`) enabling easy page-level ad-hoc Vue overrides for table cells. **(published)**
- **`ProgressColumn` table column** — Added new `ProgressColumn` (`progress` type) for displaying numeric or quantity values with a supporting progress bar. Highly customizable with custom progress calculations, max values, and status colors. **(published)**
- **`is_free` column on plans** — Added `is_free` boolean column to the plans migration, model, and seeder template. `Plan::isFree()` now checks the column first, then falls back to `monthly_price <= 0`. **(published)**
- **Generic trial `trial_plan` support** — When `trial_generic` is enabled, subscribing to a plan with `trial_days` sets `trial_ends_at` and `trial_plan` on the billable model without creating a Stripe subscription (no payment method required). Consumers must add a `trial_plan` (nullable string) column to their billable model's table. `HasPlan::currentPlan()` returns the trial plan while the trial is active, then falls back to Stripe. `BillingManager::subscriptionData()` now includes `trialPlan`. **(published)**
- **`trialPlan` in `KinetixSubscriptionData`** — TypeScript interface updated with `trialPlan: string | null`. **(published)**
- **`trialDays` in `KinetixPlanData`** — TypeScript interface updated with `trialDays: number | null`. **(published)**
- **`billing_trial_active_plan` i18n key** — New translation key in all four languages for showing the active trial plan name in the subscriptions status component. **(published)**
- **`KinetixSubscriptionStatus` trial plan display** — The subscription status component now shows which plan is being trialed during a generic trial. **(published)**

### Changed

- **Generic trial `subscribe()` flow** — When `trial_generic` is enabled and the plan has `trial_days`, `BillingManager::subscribe()` now sets up a generic trial on the billable instead of creating a Stripe subscription. Previously it created a Stripe subscription without passing trial days. **(published)**

## [0.69.3] - 2026-07-02

### Fixed

- **Subscription payment method validation bug** — Corrected `BillingManager::subscribe()` to allow starting a subscription without passing an upfront payment method if the plan is free, if starting a Stripe trial (which can be configured to not require a card upfront), or if the user already has a default payment method on file. **(published)**

## [0.69.2] - 2026-07-02

### Added

- **Plan Card Trial Badges** — Updated `KinetixPlanCard` to show a beautiful trial days badge (e.g. "14-day trial") under the price if the plan has `trialDays` and `showTrial` prop is true. **(published)**
- **Pricing Table Trial toggle prop** — Added `showPlanTrials` prop to `KinetixPricingTable` to allow host apps to hide trial badges on pricing cards when the user is ineligible. **(published)**

## [0.69.1] - 2026-07-02

### Added

- **Generic Trial Isolation Config** — Introduced `trial_generic` configuration setting to fully isolate generic trial mode (database driven) from Stripe subscription trial mode. When active, new Stripe subscriptions ignore plan `trial_days` to prevent double-trialling. **(published)**

## [0.69.0] - 2026-07-02

### Added

- **Plan Trials (`trial_days`)** — Added a `trial_days` column to the `plans` table and model properties to allow configuring trial periods per-plan. If a plan specifies trial days, new subscriptions will automatically be started with those trial days in Stripe. **(published)**
- **Trial Status reporting** — Extended `BillingManager::subscriptionData()` to return trial details (`onTrial`, `trialEndsAt`, `onGenericTrial`) to the frontend, supporting both generic trials (started via database `trial_ends_at` without card upfront) and Cashier subscription trials (card upfront). **(published)**
- **Subscription Status Card Trial Alert** — Updated `KinetixSubscriptionStatus.vue` to display a distinct amber trial badge and a trial active notification banner alerting users when they are on trial. **(published)**

## [0.68.8] - 2026-07-02

### Fixed

- **Team Billing resolution fallback** — Corrected `BillingManager::resolve()` to check if the `{team}` route parameter contains a string or integer (e.g. ID/slug from initial route parameter binding before substitution) and dynamically query the database for the corresponding Model instance, avoiding type mismatch `RuntimeException` crashes. **(published)**

## [0.68.7] - 2026-07-02

### Changed

- **Team Billing routes prefix** — Corrected the route prefix generated when `billing.teams` is active to `{team}/billing` (excluding the `/teams` segment) to match the cleaner URL patterns (`/{team}/billing/subscribe` etc.) preferred by users. **(published)**

## [0.68.6] - 2026-07-02

### Added

- **Team Billing Support** — Introduced a new `billing.teams` configuration key (controlled by `KINETIX_BILLING_TEAMS` env variable) to enable Team-scoped billing out-of-the-box. When active, billing routes are automatically prefixed with `teams/{team}/billing`, and `BillingManager::resolve()` resolves the team model from request parameters or the user's `currentTeam` relation. **(published)**

### Documentation

- **Billing Setup Guide (`docs/billing.md`)** — Updated the billing guide to document Cashier migration publishing (`vendor:publish --tag="cashier-migrations"`), `@stripe/stripe-js` installation, and steps for setting up team-scoped billing (e.g. database schema changes and AppServiceProvider model registration).

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
