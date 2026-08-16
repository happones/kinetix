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

## [0.171.1] - 2026-08-16

Documentation only — nothing a consumer receives via `vendor:publish` changed.

### Added

- `onboarding-checklist-sidebar-collapsed` gallery specimen: the same mock rail
  expanded and collapsed side by side, the second one carrying shadcn's real
  `group` + `data-collapsible="icon"` contract — so the "it folds away with the
  rail" claim is demonstrated by rendering rather than asserted in prose. Both
  sidebar specimens now pin their footer to the bottom of a fixed-height rail,
  the way a real sidebar behaves.

## [0.171.0] - 2026-08-15

The onboarding checklist stops paying for itself on every page: its state rides
on the Inertia payload, and reading it no longer writes a row.

### Changed

- **The onboarding checklist rides on the page payload instead of a request per
  mount.** It fetched on mount, which was one round-trip per page load for the
  dashboard card — and one per *navigation* for the new sidebar variant, which
  lives in the layout and is therefore mounted on every page, for a list that
  changes a handful of times in an account's lifetime. The new
  `kinetix_onboarding` shared prop carries the whole checklist, so the component
  renders from the first paint with no request and no pop-in.
  `onboarding.share` (default `true`) turns it off and restores the old
  fetch-for-yourself behaviour. `useKinetixOnboarding()` is unchanged for
  callers: `load()` becomes a no-op when the payload is there, `load(true)`
  forces a refetch, and a ticked step or a dismissal wins over the payload until
  the next Inertia response. The cost to know about: every `completedUsing`
  callback now runs on **every** request, not only where the checklist is
  mounted — keep them cheap **(published: `useKinetixOnboarding.ts`,
  `types/kinetix.ts`, `config/kinetix.php`)**.

### Fixed

- **Reading the onboarding checklist wrote a row.** `OnboardingManager::for()`
  resolved its progress with `firstOrCreate`, so merely *looking* at the
  checklist created a `kinetix_onboarding` row — harmless when only the endpoint
  called it, a write on every account's first page view now that the state is
  shared on every response. It resolves with `firstOrNew`; the row is written
  the first time the user completes a manual step or dismisses the checklist.

## [0.170.0] - 2026-08-15

The onboarding checklist gets a second shape — a condensed block for the
navigation rail, so the remaining steps travel with the user. Announcements
gain an authoring UI, an expiry date, and a place on the page payload instead
of a request per mount.

### Fixed

- **The header triggers didn't match each other.** Every one of them is meant to
  be the same `outline` + `icon-sm` button, but the **notification bell** was a
  `ghost` variant (borderless, next to four bordered buttons) and the
  **spotlight trigger** hand-rolled a 36px borderless square when collapsed — so
  on a phone, or any narrow header, two of six controls looked like a different
  control and sat 4px taller than the row. Both now build on the shared recipe
  **(published: `KinetixNotificationTrigger.vue`,
  `KinetixSpotlightTrigger.vue`)**.
- **The two unread badges were different sizes.** The bell's pill and the
  megaphone's were styled independently (18px vs 16px, different offsets and
  cutoffs) even though they sit side by side. Both now use
  `triggerCountBadgeClass` — one recipe, exported from
  `useKinetixShadcnVariants` — and are `aria-hidden`, with the count spoken by
  the button's own label instead of as a loose number **(published:
  `useKinetixShadcnVariants.ts`, `KinetixNotificationTrigger.vue`,
  `KinetixAnnouncements.vue`)**.
- **The onboarding checklist announced its progress as a bare number.** Its
  progress bar had no accessible name and no `aria-valuetext`, so a screen
  reader read "33" with nothing to attach it to, and a completed step was
  marked only by a strike-through — a purely visual signal. Both variants now
  name the bar, spell its value out ("1 of 3 complete"), hide the decorative
  icons from the accessibility tree, and carry an `sr-only` "Completed" /
  "Not completed" on every row **(published: `KinetixOnboardingChecklist.vue`
  and its two variant components)**.
- The `announcements_unread_count` translation key is now **`unread_count`** —
  the notification bell uses the same string, and the key was scoped to the
  wrong feature **(published: translations)**.

### Added

- **The onboarding checklist can live in the sidebar.** It only ever had one
  shape — a full-width card — which meant picking a page to put it on and
  accepting that a user who lands anywhere else never sees the steps they have
  left. `<KinetixOnboardingChecklist variant="sidebar" />` renders the same
  checklist, the same steps and the same progress row as a condensed block
  sized for the starter kit's navigation rail: a terse `1 of 3` counter and a
  hairline bar instead of the heading, no step descriptions, an `×` for
  dismiss, and rows where the leading circle ticks a manual step off while the
  rest of the row is the step's CTA link — both affordances, no control nested
  inside another. It carries shadcn's own
  `group-data-[collapsible=icon]:hidden`, so a rail collapsed to icons drops it
  rather than squeezing it, and the class is inert outside a sidebar. The
  default stays `variant="card"` **(published:
  `KinetixOnboardingChecklist.vue`, `Onboarding/OnboardingChecklistCard.vue`,
  `Onboarding/OnboardingChecklistSidebar.vue`, 4 translation keys × 7
  locales)**.
- **`<KinetixAnnouncementManager>`: announcements without a deploy.** The
  feature was publish-from-code only — `KinetixAnnouncements::publish()` from a
  seeder or a tinker session — so every "heads up, maintenance on Sunday" went
  through an engineer. The manager is the authoring side: write, schedule, edit
  and delete from a page in the app, with drafts (no publish date) and
  scheduled entries (a future one) listed alongside the published ones. Gated
  by the new **`manageKinetixAnnouncements`** ability, which defaults to
  `local` only, so nothing can be published to production before the host
  decides who may. New endpoints `GET announcements/manage`, `POST
  announcements`, `PUT`/`DELETE announcements/{id}`; a platform-wide entry is
  read-only inside a team (403 — it belongs to every tenant), and deleting an
  announcement takes its banner dismissals with it **(published:
  `KinetixAnnouncementManager.vue`, `useKinetixAnnouncements.ts`,
  `types/kinetix.ts`, 14 translation keys × 7 locales)**.
- **`expires_at`: an announcement can now end.** "Maintenance on Sunday" used
  to sit in the feed forever, and a tenant's list only ever grew. Past its
  expiry an entry leaves every feed, banner and unread count on its own; `null`
  (every existing row) never expires, so nothing changes for what's already
  published. Settable from the manager UI, from
  `KinetixAnnouncements::publish(..., $expiresAt)` and via the API, which
  rejects an expiry before the publish date **(published:
  `KinetixAnnouncementManager.vue`, `types/kinetix.ts`, 3 keys × 7 locales)**.
- **The feed's size is configurable and bounded.** `?limit=` (ceiling 50) and
  `announcements.feed_limit` (default 20) replace the hardcoded 20. No cursor:
  a "what's new" feed is the last handful of entries, not an archive — and with
  expiry doing its job, old news stops piling up **(published:
  `config/kinetix.php`)**.
- **Announcements ride on the page payload instead of a request per mount.**
  Both the header trigger and the banner fetched on mount, so an app whose
  layout is re-created per page paid a round-trip **per navigation** for a feed
  that changes weekly. The new `kinetix_announcements` shared prop
  (`{ unread, bannerLimit, banner }`) feeds both: the trigger renders its badge
  from the payload and loads the list only when the popover is opened (once,
  not per open), and the banner hydrates from it unless narrowed with `levels`
  or a non-default `limit`. `announcements.share` (default `true`) turns it off
  and restores the old fetch-for-yourself behaviour **(published:
  `useKinetixAnnouncements.ts`, `KinetixAnnouncements.vue`,
  `types/kinetix.ts`, `config/kinetix.php`)**.
- `header-controls` / `header-controls-narrow` gallery specimens (the whole
  trigger strip in one shot, wide and sub-`sm`) plus a scan test pinning the
  shared button recipe — the drift above was invisible in each component's own
  tests, and only showed up side by side.

## [0.169.0] - 2026-08-11

Announcements grow a second face — an in-page banner instead of a header
popover — and stop leaking read state between a user's teams.

### Added

- **`<KinetixAnnouncementBanner>`: announcements where the work happens.** An
  alert-styled surface that shows one entry at a time and rotates through the
  rest: arrows, dots, an explicit pause button and left/right keys. Rotation
  pauses on hover and on keyboard focus, and is off entirely under
  `prefers-reduced-motion` (the manual controls stay). Props: `limit` (default
  3), `levels`, `autoplay` (ms, `0` = no clock), `dismissible`, `class`.
  Announced as a carousel region with a per-slide position label, and the live
  region is only polite while the clock is stopped — auto-rotation never
  interrupts a screen reader **(published:
  `KinetixAnnouncementBanner.vue`)**.
- **`position="fixed-top"`: the same banner pinned to the viewport**, above the
  page and below Kinetix's overlays (a modal still covers it), for the notice
  everyone has to see whatever page they're on. It publishes its measured
  height — which changes as entries wrap — as
  `--kinetix-announcement-banner-height` on `<html>`, so a layout can reserve
  the space with `padding-top: var(--kinetix-announcement-banner-height, 0px)`
  and get it back the moment the banner is dismissed. `fixedWidthClass`
  (default `max-w-3xl`) sizes the bar; the slide-in is skipped under
  `prefers-reduced-motion` **(published: `KinetixAnnouncementBanner.vue`)**.
- **Dismissing is per announcement, and persisted.** Closing a banner hides
  *that* entry for that user on every device — the feed's single "I read
  everything" timestamp could not express this. New
  `kinetix_announcement_dismissals` table, `GET {prefix}/announcements/banner`
  (`?limit=`, `?levels=`) and `POST {prefix}/announcements/{id}/dismiss`, which
  resolves through the team-scoped query, so another tenant's id is a 404.
  `AnnouncementManager::banner()` / `dismiss()`;
  `useKinetixAnnouncementBanner()` → `{ announcements, loading, load, dismiss }`
  **(published: `useKinetixAnnouncements.ts`)**.
- **New config** `announcements.banner_limit` (default 3, server ceiling 10) —
  the default rotation length when the component doesn't pass its own
  **(published: `config/kinetix.php`)**.
- **11 new i18n keys × 7 locales** (`announcements_level_*`, `_previous`,
  `_next`, `_dismiss`, `_pause`, `_play`, `_slide_position`, `_go_to`,
  `_unread_count`) **(published: translations)**.

### Fixed

- **Multi-tenant: reading one team's feed cleared every team's badge.** Read
  state was ONE row per user, so a user in two teams could open team A's feed
  and have team B's unread count drop to zero without ever seeing it. It is now
  one row per (user, team), plus a `team_id` NULL row for the platform-wide
  entries — read once, read everywhere, instead of following the user from team
  to team. Existing rows stay NULL, so nothing re-appears as unread after the
  upgrade; `kinetix:doctor` flags the table when the column is missing.
- **The feed had no index to sort on.** `where team_id = ? or team_id is null
  order by published_at desc` was served by two single-column indexes, leaving
  the sort to the database. Added a composite `(team_id, published_at)` index.
- **The feed endpoint ran the same read-state query twice** (`feed()` and
  `unreadCount()` back to back); it is now resolved once per request.
- **The announcements popover ignored the app's language.** The level badge
  printed the raw slug (`feature` in a Spanish feed) and dates were formatted in
  the *browser's* locale, not the app's. Both now go through the shared
  `useKinetixAnnouncementFormat()` **(published: `KinetixAnnouncements.vue`,
  `useKinetixAnnouncements.ts`)**.
- **Popover a11y**: the "new" dot carried an `aria-label` on a generic `<span>`
  (which screen readers ignore) — it is now `aria-hidden` with an `sr-only`
  label inside the heading; the trigger's label states what the badge counts
  instead of leaving it as a bare number; and the feed scrolls through the
  `ScrollArea` primitive rather than a raw `overflow-y-auto`
  **(published: `KinetixAnnouncements.vue`)**.
- The `Alert` primitive accepts a `role` override (default still `alert`), so a
  surface that rotates or is always present doesn't have to be an assertive live
  region **(published: `primitives/Alert.vue`)**.

### Upgrading

```bash
php artisan vendor:publish --tag=kinetix-announcements-migrations --force
php artisan migrate
```

Three additive, idempotent migrations: `team_id` on the views table, the
dismissals table and the feed index.

## [0.168.1] - 2026-08-10

### Fixed

- **Billing: an empty string is no longer mistaken for a Stripe id.** Every
  identifier check in the billing module went through `!== null`, so a
  `stripe_id` (or price, or payment method) of `''` — what a form default, a
  CSV import or a `fill()` routinely leaves in a column — was treated as a real
  value and only failed deep inside the Stripe API call:
  - `ensureStripeCustomer()` believed a blank `stripe_id` was a customer and
    skipped creation, breaking every later call. It now creates the customer —
    clearing the blank id first, because Cashier's own `hasStripeId()` is a
    plain null check and `createAsStripeCustomer()` would otherwise throw
    `CustomerAlreadyCreated` and leave the billable permanently stuck. New
    `BillingManager::hasStripeCustomer()` exposes the stricter check.
  - `HasPlan::currentPlan()` matched a blank subscription price against the
    plan table, so plans whose price columns were seeded as `''` all matched
    and **silently granted the wrong plan's features**. A blank price now
    matches nothing.
  - A blank `payment_method` is treated as "none given" (`subscribe()` takes
    the no-card path instead of sending `''` to Stripe), and
    `addPaymentMethod('')` fails immediately with a clear message.
  - `Plan::stripePriceId()` returns `null` for a blank column, so `subscribe()`
    reports "plan has no Stripe price id for this cycle" instead of forwarding
    an invalid id; a blank subscription price is reported as `null` in
    `subscriptionData()`, and a blank `trial_plan` slug no longer queries.

## [0.168.0] - 2026-08-10

The Help Center becomes properly multilingual: the manual now follows the
reader's language live, says which language they're actually reading, and
costs no file reads to search.

### Added

- **The language is part of the request**: every help endpoint accepts
  `?locale=` — validated against the locales the manual is authored in
  (`help.locales`, else the Locale module's, else the variants found on disk),
  so untrusted input can neither pick files nor widen the cache keyspace — and
  answers with `Content-Language` + `Vary`. The URL now varies per language, so
  no browser or CDN cache can serve one language's payload to another.
- **Per-article language switcher**: an article payload carries `locale`,
  `requestedLocale`, `isFallback` and `availableLocales`, and
  `<KinetixHelpArticle>` renders chips to read a single article in another
  language without changing the app's locale (`hide-language-switcher` opts
  out) **(published: `KinetixHelpArticle.vue`, `types/kinetix.ts`)**.
- **Untranslated content is marked, never silently swapped**: the article body
  carries its real `lang` (plus `dir="auto"`) with a notice naming the language
  shown, and index/search entries get a language badge and their own `lang`
  attribute — so a screen reader stops reading English as if it were Spanish
  **(published: `KinetixHelpCenter.vue`, `KinetixHelpArticle.vue`)**.
- **New config**: `help.locales`, `help.fallback_locale` (the language the base
  `.md` files are written in, and the last resort before them),
  `help.hide_untranslated` (strict mode: an article with no variant in the
  active language disappears from the index, search and its own URL) and
  `help.cache.strategy` (`fingerprint` | `ttl`) **(published:
  `config/kinetix.php`)**.
- **Localized screenshots**: `kinetix:help-screenshots --locale=es` stores a
  capture set under `{path_prefix}/{locale}/`, and embeds resolve it for
  articles written in that language, falling back to the shared capture — the
  markdown never changes.
- **`kinetix:help-status`**: translation coverage per article and locale, with
  `--strict` for a CI gate and `--locale` to narrow the report.
- **`kinetix:make-help-page --locale=es [--from=slug]`**: scaffolds the missing
  variants with the front matter verbatim (permissions and ordering must not
  drift between languages) and the heading skeleton with TODO markers.
- 3 new translation keys (`help_translation_missing`, `help_read_in`,
  `help_untranslated`) across all seven locales **(published)**.

### Fixed

- **The Help Center kept the previous language after a switch**: it fetched
  only on mount, and an Inertia reload re-renders the page component without
  re-running `onMounted`, so the index and the open article stayed in the old
  language until a full page load. `useKinetixHelp` now watches the active
  language and re-fetches **(published: `useKinetixHelp.ts`)**.
- **Heading anchors erased non-Latin headings**: the TOC slug used an
  ASCII-only rule, so `Configuración` lost letters and Cyrillic/CJK/Arabic
  headings collapsed to identical ids (`section`, `section-1`, …), breaking
  every deep link. Slugs are now Unicode-aware, folding diacritics on Latin
  text only (an Arabic combining mark changes the letter, it doesn't decorate
  it), and repeated headings dedupe as `-2`, `-3` **(published:
  `useKinetixHelpToc.ts`)**.

### Changed

- **Search stopped re-reading the manual**: each locale's articles are built
  once per request into an index holding both the metadata and the plain-text
  search corpus, so a query costs zero file reads (it previously re-read and
  re-parsed every article, twice, on every keystroke). The index memo is keyed
  on the files' mtimes so a long-lived worker can't serve a stale manual, and
  `HelpManager` is now a singleton shared by the endpoints and the Spotlight
  source.
- `useKinetixHelp` caches payloads per language client-side (switching back and
  forth costs no request); `clearKinetixHelpCache()` drops them **(published:
  `useKinetixHelp.ts`)**.
- Dev-only: the gallery gained Help Center specimens, and `docs/help-center.md`
  documents the whole translation workflow with light/dark screenshots.

## [0.167.0] - 2026-08-10

An optional plan-lock UI for the billing suite: the padlock every SaaS grows —
in a card, over a panel, in a banner or beside a menu item — plus the upgrade
dialog behind it. Hiding a plan-locked feature and padlocking it are now both
one-liners on the same dot-paths.

### Added

- **`<KinetixPlanLock>` — the plan padlock (billing UI, opt-in)**: one
  component for every way a plan-locked feature is presented, gated on the
  same dot-paths the server enforces (`feature` / `limit` + `count`). Four
  presentations via `variant`: `card` (dashed lock card replacing the content
  — what `<KinetixPlanGate>` renders), `overlay` (the content stays visible
  but blurred, dimmed and `inert` under a centred lock), `banner` (row-shaped
  upsell strip) and `badge` (the content dimmed with a padlock appended, any
  click opening the upgrade dialog instead of navigating — sidebar items, tab
  triggers). Copy is prop-driven (`featureName`, `title`, `description`,
  `ctaLabel`, `badgeLabel`) with translated defaults, and `#locked` replaces
  the lock UI entirely (receives `remaining` + an `open()` callback). A lock
  with no `feature`/`limit` prop is an unconditional upsell **(published:
  `KinetixPlanLock.vue`, `Billing/PlanLockPanel.vue`,
  `Billing/PlanLockCta.vue`)**.
- **`<KinetixUpgradeModal>`**: the shared upsell dialog the locks open (built
  on the `KinetixModal` primitive), also usable standalone via
  `v-model:open` + `feature-name`. Without an upgrade URL configured, no CTA
  renders anywhere — a lock never ships a dead-end button **(published:
  `KinetixUpgradeModal.vue`)**.
- **App-wide lock defaults**: new `kinetix.billing.lock` config block
  (`variant`, `modal`, `blur`, `badge_label`, each with a
  `KINETIX_BILLING_LOCK_*` env) shared to the SPA as `kinetix_billing.lock`,
  so a single decision applies to every lock while per-instance props still
  win **(published: `config/kinetix.php`, `types/kinetix.ts`)**.
- 5 new translation keys (`plan_locked_feature`, `plan_locked_hint`,
  `plan_upgrade_modal_title`, `plan_upgrade_modal_body`,
  `plan_upgrade_dismiss`) across all seven locales **(published)**.

### Changed

- **Plan gating is evaluated in one place**: the new `useKinetixPlanAccess()`
  helper in `useKinetixPlan()` is now the single implementation of "does this
  plan allow it?" — `<KinetixPlanFeature>` uses it, and `<KinetixPlanGate>`
  became a thin `<KinetixPlanLock variant="card">` wrapper (same props, same
  `#locked` slot, same CTA behaviour, no migration needed) **(published:
  `useKinetixPlan.ts`, `KinetixPlanFeature.vue`, `KinetixPlanGate.vue`)**.

### Documentation

- `docs/billing.md` gained a padlock section (variant table, prop table, config
  block, `#locked` slot) with light/dark screenshots of all four presentations,
  plus a "hide it or padlock it — your call" section spelling out that both
  behaviours are first-class and that neither replaces server-side enforcement.
- Dev-only: `npm run screenshots -- <name>` now accepts name filters instead of
  always recapturing the entire gallery.

## [0.166.0] - 2026-08-07

Drop-preview feedback across every drag surface, a kanban board reactivity
fix, and an enums documentation pass.

- **Drop-preview ghosts (kanban + calendar)**: while a drag is in flight, the
  hovered drop target now previews the landing spot with a dashed ghost
  placeholder labelled with the dragged item's title — kanban columns (at the
  end of the column, where the card lands; suppressed in the card's own column
  and in virtualized columns) and calendar month cells, all-day banner and
  hour slots. Built on a new shared `KinetixDropGhost` primitive (aria-hidden,
  pointer-transparent, `prefers-reduced-motion`-aware) **(published:
  `KinetixDropGhost.vue`, `KinetixKanban.vue`, `Kanban/KanbanColumn.vue`,
  `KinetixEventCalendar.vue`, `useKinetixCalendarEventMove.ts`)**.
- **Live-preview reorder (tables + media library)**: new generic
  `useKinetixListReorder` composable — live preview under the pointer, a
  single commit on drop, revert on a cancelled drag. Table row reordering now
  wraps it (`useKinetixTableReorder`): the in-flight row renders as a
  translucent preview of its landing position, and an Escape/out-of-bounds
  drag reverts instead of leaving an unpersisted order. The media library
  grid (which previously reordered only on drop, with no feedback) uses it
  directly **(published: `useKinetixListReorder.ts`,
  `useKinetixTableReorder.ts`, `KinetixTable.vue`,
  `KinetixMediaLibrary.vue`)**.
- **Shared drag styling vocabulary**: `kinetixDragStyles.ts` centralizes the
  drag-source dim and drop-preview classes reused by kanban, calendar, tables
  and the media library **(published)**.
- **Kanban: board resyncs on server updates**: `KinetixKanban` now watches
  the `kanban` prop and resyncs its local column state whenever Inertia ships
  fresh data (modal CRUD, post-move reloads) — previously the board kept its
  first-render snapshot until a manual browser refresh. A cancelled drag no
  longer leaves a column's drop highlight stuck for the next drag
  **(published: `KinetixKanban.vue`, `Kanban/KanbanColumn.vue`)**.
- **Enums & `HasLabel` docs**: documented the duck-typed enum resolution (any
  enum with a public `getLabel()`/`getColor()`/`getIcon()` method resolves
  everywhere — implementing the Kinetix contracts is optional, and enums
  written against equivalent third-party label contracts work unchanged), the
  `HasLabelOptions` `::options()` helper, and enum-driven Selects end-to-end
  (forms + tables docs and skills).
- **Docs: 31 broken in-page anchors fixed** — VitePress slugs collapse
  double dashes and prefix numbered headings with `_`; every cross-link is
  now validated against the real heading slugs.

## [0.165.0] - 2026-08-06

Kanban/Calendar interaction parity: modal-safe (flat) forms, calendar
drag-and-drop rescheduling, touch drag on mobile for both boards.

- **Flat modal forms — card-in-modal eliminated**: `KinetixForm` and
  `KinetixInfolist` gain a `flat` prop that renders `Section`/`Tabs` without
  card chrome (divided groups with heading/description intact), recursively
  through Grid/Fieldset/Tabs/Wizard/Split/Repeater. The table record modals,
  the view-modal infolist and relation-manager attach forms pass it
  automatically — a resource form WITH `Section`s is now safe in `--simple`
  record modals **(published: `KinetixForm.vue`, `KinetixFormSchema.vue`,
  `KinetixFormTabs.vue`, `KinetixFormWizard.vue`, `KinetixInfolist.vue`,
  `KinetixInfolistEntries.vue`, `KinetixTable.vue`,
  `KinetixRelationManager.vue`)**.
- **Calendar drag-and-drop rescheduling**: `Calendar::moveable()` (+
  `authorizeMove()`, `moveScope()`) makes events draggable to another day
  (month view — keeps time-of-day) or hour slot (week/day — snaps to the
  hour); the end column shifts by the same delta so durations survive. New
  signed `POST {prefix}/tables/calendar-move` endpoint mirrors the
  kanban-move guard chain (user-bound expiring descriptor, policy default
  `update`, moveScope → 404, unparseable start → 422). Optimistic with revert
  + error toast, `router.reload()` on success, new `event-moved` emit;
  `CalendarData` gains a nullable `model` descriptor **(published:
  `KinetixEventCalendar.vue`, `useKinetixCalendarEventMove.ts`)**.
- **Touch drag on mobile** for BOTH kanban and calendar via the new
  `useKinetixTouchDrag` composable: long-press (~250ms) lifts a floating
  clone that tracks the finger, the hovered target highlights, the board
  auto-scrolls near horizontal edges, and moving before the long-press simply
  scrolls (the gesture is never hijacked) **(published)**.
- **Kanban drag feedback**: the source card dims, the hovered column
  highlights (dragenter depth counter), and cards FLIP-animate into place via
  `TransitionGroup` — disabled for virtualized columns and under
  `prefers-reduced-motion` **(published: `KinetixKanban.vue`,
  `Kanban/KanbanColumn.vue`)**.
- **Kanban `card-click`**: `<KinetixKanban>` emits `card-click(card,
  columnKey)` on click/<kbd>Enter</kbd> — the hook for edit-in-modal or
  navigation (cards were previously inert).
- Calendar keyboard moves: Alt + arrow keys on a focused event (±1 day
  everywhere; ±1 week in month view, ±1 hour in the time grids), announced
  through the shared live region with focus restore; moveable chips point
  `aria-describedby` at sr-only instructions. 4 new i18n keys ×7 locales.
- Docs: kanban/calendar gain full CRUD wiring guides (in-page modal — flat
  form + `->dispatch()` header action + the `kinetix_toast` controller
  contract — or dedicated pages); actions doc adds "header actions that open
  a form in a modal" and clarifies `->modal()` is table-only; forms doc
  covers `flat`.

## [0.164.0] - 2026-08-06

Accessibility backlog cleared.

- **Field helper text, end to end**: `Field::helperText(string|Closure)`
  renders a hint under the field with an addressable id (`{name}-help`), and
  the control's `aria-describedby` now CHAINS helper + error
  (`{name}-help {name}-error`) — screen readers hear both **(published:
  `KinetixFormSchema.vue`, `Form/KinetixFormField.vue`)**. The DTO's
  `description` field existed but was never settable nor rendered for fields.
- **Kanban semantics**: cards expose `aria-roledescription` and point
  `aria-describedby` at a per-board screen-reader-only instructions element
  (the arrow-key move affordance is now discoverable); columns are labelled
  groups (`role="group"`, name + count) **(published: `KinetixKanban.vue`,
  `Kanban/KanbanColumn.vue`)**. 2 new translation keys ×7 locales.
- Note: the rest of the audited backlog was already shipped — `aria-sort`
  (v0.132), result-count announcements, keyboard alternatives for every
  drag-and-drop, and the global `prefers-reduced-motion` kill-switch (v0.155)
  that supersedes per-component `motion-reduce:` pairing.

## [0.163.0] - 2026-08-06

The v4 congruence backlog from the v0.155 audit, all **(published)**:

- **`primitives/KinetixBadge.vue`** — the badge/pill primitive (status-color
  soft pill via `statusBadgeClass`, shadcn `variant` mode via the previously
  unused `badgeVariants()`, `size="sm"` tab-badge size). Migrated the
  hand-copied pill class strings across 9 components (relation manager
  headings/tabs, table badge cells, infolist badges, member list, list/chart/
  stats widgets, webhook logs); non-status pills (raw-palette tints, code
  chips, dots) deliberately left as-is.
- **3 modals onto the `KinetixModal` shell**: `KinetixRoleEditorModal`,
  `KinetixRoleDeleteDialog`, `LogDetailModal`, `KinetixImportModal` — v4
  overlay/panel animations, shared close button, focus trap and z-scale come
  from the shell now. `KinetixFilePreview` stays on its own dialog BY DESIGN:
  it is a lightbox (edge-to-edge body, custom zoom/download toolbar) that the
  dialog shell cannot express — it already carries the v4 animation set.
- **Relation manager tab strip on Reka Tabs** — `aria-controls` wiring and
  roving tabindex (arrow-key navigation) come from the primitive; tab
  activation still writes `?relation=` to the URL.
- **Tour transitions**: the tour layer fades in/out, the tooltip zooms on
  mount and glides between steps like the spotlight ring.

## [0.162.0] - 2026-08-06

Metered usage + credits — the consumption backend the usage meters were
missing, closing the 2026-07 roadmap trio (plan gating → business hours →
this).

- **`HasMeteredUsage` billable trait** (tables `kinetix_usage` +
  `kinetix_credits`, published under `kinetix-billing-migrations`):
  `consume($key, $n)` records atomically (row locks; the plan's
  `features.usage.*` allowance is spent before credits; past
  `allowance + credits` a `UsageLimitExceededException` (403) aborts with
  nothing recorded), `canConsume()`, `currentUsage()` (resets per calendar
  month — `usagePeriodKey()` overridable), `remainingUsage()` (null =
  unlimited; unlimited keys never block or touch credits).
- **Top-up credits**: `addCredits($key, $n)` / `creditsFor($key)` — not
  period-scoped, persist until consumed.
- **Zero-wiring meters**: the trait ships a default `meteredUsage()`, so
  `<KinetixUsageMeters>` renders one meter per plan `usage.*` key with real
  tracked numbers; with credits the meter's limit shows the purchased
  headroom (`allowance + credits`).

## [0.161.0] - 2026-08-06

Weekly business-hours field — the roadmap's schedule kit for
booking/appointment apps.

- **`BusinessHours::make('hours')`** form field: per-day editor with an
  enable switch, one or more `HH:MM` ranges, add/remove range and "Apply to
  all days"; day names via `Intl` in the active locale; defaults to
  Monday–Friday 09:00–17:00 **(published: `KinetixBusinessHours.vue`)**.
- **`WeeklySchedule` value object** (`Support`): `isOpenAt($moment)` /
  `isOpenNow($tz)` / `effectiveSchedule()` / `rangesFor($day)`; overnight
  ranges wrap past midnight (`22:00–02:00`); `fromArray()` normalizes loose
  input. **`AsWeeklySchedule`** Eloquent cast round-trips the VO.
- **`kinetix_weekly_schedule` validation rule** (string-registered, usable
  on any validator) — the field seeds it automatically. New
  `validation_weekly_schedule` + 4 editor keys ×7 locales.
- **`primitives/KinetixSwitch.vue`** extracted as the single home of the
  shadcn switch recipe (the Toggle field and the new editor both build on
  it) **(published)**.

## [0.160.0] - 2026-08-06

Plan-gating kit: capabilities + limits enforcement wired end-to-end on the
existing Billing plan foundation.

- **Billable sugar** (HasPlan): `planAllows('api')` / `planLimit('projects')`
  / `isWithinPlanLimit('projects', $count)` over the namespaced features
  convention (`features: { capabilities: {...}, usage: {...} }`). No plan →
  capabilities denied (fail closed), limits unlimited (fail open).
- **`kinetix.plan:` middleware** — capability route gating with the upsell
  pattern: denied web requests redirect to the new
  `kinetix.billing.upgrade_url` config (`KINETIX_BILLING_UPGRADE_URL`) with a
  flash toast; JSON (or no URL) gets the 403. The dot-path `plan.feature`
  middleware is unchanged.
- **`EnforcesPlanLimits` model trait** — creating past the plan's
  `usage.{plural_snake_model}` limit throws `PlanLimitExceededException`
  (403, translated); overridable key/billable/count-query; unlimited plans
  skip the COUNT and billing-less environments skip the check.
- **`<KinetixPlanGate>`** — `<KinetixPlanFeature>` with a built-in locked
  state (lock card + Upgrade CTA to the upgrade URL; `#locked` slot
  overrides) **(published)**; `useKinetixPlan()` gains `allows()` +
  `upgradeUrl`; the `kinetix_billing` shared prop now carries `upgradeUrl`.
  4 new translation keys ×7 locales.

## [0.159.0] - 2026-08-06

Relation managers: groups, collapsible sections and a table-level empty-state
API — the last items of the relation-managers arc.

- **Groups**: managers sharing `protected static ?string $group = '…'` render
  as ONE tab, their sections stacked inside (each with its own heading). The
  tab badge sums the members' numeric badges; the group's `?relation=` key is
  the raw label slugged (locale-stable); opening the group tab loads all its
  lazy members in one request **(published: `KinetixRelationManagers.vue`,
  `KinetixRelationManager.vue`, `types/kinetix.ts`)**.
- **Collapsible sections**: `$isCollapsible` adds a collapse toggle to the
  manager's heading (stacked layout / group tabs); `$isCollapsed` starts
  closed and implies collapsible. A collapsed lazy section defers its load
  until expanded. No-op in a plain tab, where the heading is hidden.
- **Table empty state** (all tables, not just managers):
  `emptyStateHeading()/emptyStateDescription()/emptyStateIcon()/emptyStateActions()`
  render the shared `<KinetixEmptyState>` card instead of the plain
  "No records found" line **(published: `KinetixTable.vue`)**. Actions behave
  like toolbar actions (`->modal()` included), unauthorized ones are dropped,
  and a `$readOnly` manager strips them. New `TableEmptyStateData` DTO on
  `TableData.emptyState`.
- `kinetix:make-relation-manager` scaffolds commented `$group`/`$isCollapsible`
  hints.

## [0.158.0] - 2026-08-06

- Relation-scoped export: `ExportAction::make()->exporter(...)` now works in
  a relation manager's toolbar, footer and bulk actions — the manager wires
  its signed descriptor into the export-start URL, the endpoint validates it
  (user-bound, expiring, parent `view` policy, exporter model must match the
  related model) and the queued export intersects the exporter's own
  `query()` with the relation's keys, so tenant scoping still applies and a
  bulk export's ids narrow further. A parent deleted before the job runs
  exports zero rows.
- **Breaking (fail-loud only):** an ExportAction inside a manager without
  `->exporter()`, or with an exporter for a different model, throws at
  serialize time (previously EVERY toolbar/footer export threw).
  `ImportAction` inside a manager still throws.

## [0.157.0] - 2026-08-06

- Lazy relation managers: `protected static bool $isLazy = true` defers a
  manager to its tab activation — the initial render serializes only the tab
  stub (title + badge; `getBadge()` still runs), so no table queries execute
  for unopened tabs. Activating the tab revisits with `?relation={relationship}`
  and shows a skeleton **(published: `KinetixRelationManager.vue`,
  `KinetixRelationManagers.vue`)**; afterwards behavior matches an eager
  manager. A lazy manager always defers, even as the first tab.
- `RelationManagerData.table` is nullable with a `deferred` flag
  **(published: `types/kinetix.ts`)**; new `relation_loading` key in 7 locales;
  `kinetix:make-relation-manager` scaffolds a commented `$isLazy` hint.
- Serialize-time misconfiguration guards fire when a lazy manager loads, not
  on its stub; the stacked layout (`tabs: false`) supports at most one lazy
  manager per page (single `?relation=` param) — use the tabs host for several.

## [0.156.0] - 2026-08-06

- Writing pivot data in BelongsToMany relation managers — three paths, every
  write restricted to `withPivot()` columns (undeclared fields/columns throw
  at serialize time).
- Attach-modal pivot fields: `AttachAction::make()->form([...])` renders below
  the record picker **(published: `KinetixRelationManager.vue`)**; the attach
  endpoint revalidates server-side and writes the state to each attached
  record's pivot row; 422 errors render inline (`kinetixFetch` carries the
  error bag on its thrown error — **published: `useKinetixHttp.ts`**).
- Pivot fields in the manager's `form()`: plain-named fields matching
  `withPivot()` columns fill from the pivot row on edit and save via
  `updateExistingPivot`; on create they pass as the attach's pivot data; a
  pivot column beats a same-named related attribute; the view modal resolves
  `pivot.*` infolist entries.
- Inline-editable `pivot.*` columns route through `updateExistingPivot` on the
  pivot row (query-builder writes — model events don't fire); a dotted
  editable column on a relation-less table is now rejected (403) instead of
  written as a literal attribute.

## [0.155.0] - 2026-08-06

- shadcn new-york-v4 congruence sweep, all **(published)**: 12 floating
  surfaces that popped in with zero animation now carry the full v4 set
  (fade + zoom + directional slide) and 8 partial sets were completed;
  remaining hand-rolled modals aligned to the v4 dialog line; drawer surfaces
  moved to `shadow-lg` + v4 tokens; the wizard step tooltip uses the v4
  tooltip recipe.
- Tailwind v4 bug fixed: `w-[--var]` implicit `var()` was dropped, so
  combobox/timezone popovers had silently stopped matching their trigger
  width — now `w-(--var)` syntax plus reka `origin-(--…-transform-origin)` /
  available-height vars.
- `prefers-reduced-motion` is now honored at the OS level; hover-only
  affordances (copy/confidential cell buttons, saved-view and media-tile
  controls) are keyboard-reachable via focus-visible escapes; calendar nav
  buttons use `buttonVariants({ ghost, icon-sm })`; the DataTable renders the
  shared `<KinetixTableColumnToggle>`.
- New shared consts in `useKinetixShadcnVariants` (`popoverAnimationClass` /
  `popoverContentClass` / `menuContentClass` / `tooltipContentClass`) and a CI
  scan test failing any reka floating surface that drops part of the v4
  animation set.

## [0.154.1] - 2026-08-06

- **(published)** The modal close button anchored to the viewport corner
  instead of the panel — the panel was missing `relative`; fixed so
  `absolute top-4 right-4` anchors inside the panel.

## [0.154.0] - 2026-08-06

- **(published)** New shared modal shell `primitives/KinetixModal.vue` on the
  shadcn new-york-v4 dialog line: `bg-black/80` overlay, `bg-background`
  panel with fade + zoom-95 at 200ms, v4 close button and header/footer
  stacks, on the Kinetix z-scale with focus trap. Migrated onto it:
  `KinetixConfirmModal`, the table's create/edit and view record modals, and
  the relation-manager attach/associate picker; `KinetixSheet` aligned to the
  v4 sheet motion.
- **(published)** DRY convention now REQUIRED in the `kinetix-tables` /
  `kinetix-resources` / `kinetix-forms` skills: reuse `KinetixButton` (or
  `buttonVariants()`) and build modals on `KinetixModal`; the confirm-modal
  footer and pagination pagers dropped their hand-copied class strings.

## [0.153.0] - 2026-08-06

- **(published)** `TextColumn` covers the field spectrum: array states render
  one pill per item with `->badge()` or implode via `->separator(', ')`;
  `->html()` for rich-text attributes (with `->limit()` stripping tags before
  truncation); `->numeric($decimals, $locale)`; per-cell links via
  `->url(fn ($record) => …, openUrlInNewTab: true)`; `->wrap()`; a rating
  recipe via `formatStateUsing` is documented instead of a dedicated column.
- **(published)** `tooltip()` on every column; `IconColumn` gains
  `trueIcon()/falseIcon()/trueColor()/falseColor()` and `size()` and resolves
  the full shared icon map; `copyable()` now works on badge cells too.
- Rendered light/dark screenshots of every column type in docs/tables.md via
  `npm run screenshots`; the `kinetix-tables` skill rewritten with a
  "Choosing a column" decision table and a Table-builder surface list.
- Fixed: `ColumnData::size` typed `int|string|null`; `ColorColumn` no longer
  shadows `copyable()`; `formatRecord()` uses hot-path getters instead of a
  full `toArray()` per cell; `docs/relation-managers.md` freshness pass
  (modal-first examples, fixed section numbering, expanded API table).

## [0.152.0] - 2026-08-06

- Toasts rendered unstyled on hosts missing the vue-sonner stylesheet:
  `kinetix:install` now adds `@import 'vue-sonner/style.css';` to
  `resources/css/app.css` (idempotent, inserted after the last `@import`).
  **Existing installs: re-run `php artisan kinetix:install`** or add the
  import by hand.
- The `kinetix_toast` protocol is documented in the `kinetix-notifications`
  skill and docs: both flash shapes, per-flash uuid (no client dedupe),
  fallback rules, and the two prerequisites (`<KinetixToaster />` mounted
  once + the stylesheet).

## [0.151.0] - 2026-08-06

- Pivot columns in BelongsToMany relation managers: declare
  `->withPivot('role')` and address it as `TextColumn::make('pivot.role')` —
  display, **sorting and searching** included. The manager hydrates a real
  Pivot model per record, so badges, `formatStateUsing()` and descriptions
  work; row ids always stay the related model's keys; a custom `->as()`
  accessor is honored.
- Sort/search on `pivot.*` qualify against the joined pivot table;
  `KinetixQuery::search()` can now LIKE a table-qualified dotted column whose
  first segment isn't a relation.
- Editable pivot columns throw at serialize time — inline edit would write to
  the related model instead of the pivot row.

## [0.150.0] - 2026-08-06

- Policy ↔ permission delegation doctrine: `docs/permissions.md` §1.5 and the
  `kinetix-permissions` skill now REQUIRE model policies to delegate abilities
  to `$user->can('{feature}.{ability}')` — the policy owns the tenancy
  boundary, the matrix owns the capability, and an explicit
  `$user->ownsTeam(...)` clause keeps the policy correct with `owner_bypass`
  off.
- `owner_bypass` semantics documented: it grants the team owner every
  REGISTERED ability (a registry-scoped `Gate::before`); model policies still
  run, so it never crosses the tenancy boundary.
- `kinetix:doctor` flags features whose synced permissions coexist with a
  policy returning static `true`s, and registered features with no policy at
  all; `kinetix:make-resource` next-steps spell out the delegation pattern.

## [0.149.0] - 2026-08-06

- BelongsToMany relation tables were join-unsafe: `SELECT *` across the pivot
  join let pivot ids clobber row ids (sending Edit/Delete/Detach to the wrong
  record), search/sort 500'd on shared column names, and inline cell edits and
  drag reorder were dead. Relation queries now select the related model's
  columns qualified, and cell-update/reorder resolve through the parent's
  relationship for every relation type.
- `$readOnly` now strips footer actions too (and footer actions join the
  manager's wiring/guards); a clicked header sort wins over any order the
  relation carries; toolbar/footer `ExportAction`/`ImportAction` inside a
  manager throw at serialize time (they'd cover the whole model — a
  data-exposure surface; bulk export of selected rows remains); the Create
  modal action is auto-gated with `create` on the related model's policy.
- **(published)** The active manager tab lives in the URL (`?relation=…`,
  written client-side); create/edit/view modals gain `role="dialog"` +
  `aria-modal` + a label; query-state polish: search/filter/per-page reloads
  `replace` history, multi-value query params survive reloads, saved-view
  keys namespaced per manager.
- `kinetix:make-relation-manager --attach / --associate` compose
  Attach/Detach or Associate/Dissociate into the stub; new Playwright E2E
  suite (`npm run test:e2e`) drives the gallery specimen in real Chromium.

## [0.148.0] - 2026-08-06

- Server-flashed toasts: `->with('kinetix_toast', 'msg')` (string → success)
  or `['type' => 'error', 'message' => …]`; **(published)**
  `<KinetixToaster />` watches the shared prop (per-flash uuid lets repeats
  fire). Kinetix record endpoints and `kinetix:make-resource` controllers
  flash on every create/update/delete/restore/force-delete; new
  `record_restored` / `record_force_deleted` keys in all 7 locales.
- **(published)** Submitted form values survive a failed validation
  round-trip: `KinetixForm` keeps the user's values when the incoming render
  carries validation errors, and syncs again on the next error-free render.
- **(published)** Standalone `<KinetixWizard>` auto-jumps to the first errored
  step when `errorSteps` changes (unless the current step already holds one).

## [0.147.0] - 2026-08-06

- Relation managers: full modal CRUD — declare `form()` (and optionally
  `infolist()`) on the manager and flag table actions with
  `->modal('create'|'edit'|'view'|'delete')`; parent-bound endpoints wire
  automatically (no routes, controllers, or parent FK field). Create goes
  THROUGH the relationship (FK/morph stamped, forged FKs ignored,
  BelongsToMany creates and attaches); Edit/View/Delete resolve through it
  (other parents' ids 404; BelongsToMany delete drops the pivot row). Guarded
  by the signed descriptor, the parent's `update` policy, and the child
  model's policy; `->modal()` without the matching schema throws at serialize
  time. `kinetix:make-relation-manager` scaffolds the convention.
- `AssociateAction` / `DissociateAction` (HasMany/MorphMany): Associate picks
  records not owned by any parent (FK `NULL`, searchable on
  `$recordTitleAttribute`) and re-parents them; Dissociate confirms and nulls
  the FK, never deleting. Same descriptor + parent `update` policy contract;
  wrong relation type throws. New i18n keys in all 7 locales.
- **(published)** Infolists got the shadcn look (card sections with icon
  chips, weighted values, pill badges, ringed images, italic empty
  placeholders); `KinetixInfolist` gives a bare schema a card surface
  automatically (`:surface="false"` opts out); the `kinetix:make-resource`
  Show page scaffolds a two-column `Section` infolist.

## [0.146.0] - 2026-08-06

- `kinetix:install` scaffolds `App\Providers\KinetixServiceProvider` by
  default and registers it in `bootstrap/providers.php` (idempotent) — all
  Kinetix registration belongs there, with registrar-per-module classes; opt
  out with `--skip-provider` (`--provider` is a deprecated no-op).
  `kinetix:make-resource` now refuses to overwrite existing files unless
  `--force`.
- `kinetix:make-resource` hardening: the controller enforces the model policy
  on every endpoint via a policy-if-exists `authorizeAction()` helper; the
  scaffolded `CreateAction` is gated with `->authorize('create',
  Model::class)`; the resource overrides `permissionFeature()` so CRUD
  abilities appear in the role matrix; the index scopes through
  `Resource::getEloquentQuery()`; `mutateFormDataBeforeSave()` stamps
  `team_id` on create and strips it on edit; `--generate` excludes
  server-owned/hidden/secret columns; `--soft-deletes` now ships a
  `TrashedFilter` plus Restore/ForceDelete row actions.
- **(published)** Grouped record actions work again: `KinetixActionDropdown`
  accepts the row's `record` and emits `action-click` to its host, wired
  through `KinetixTable`, `KinetixDataTable`, the toolbar,
  `KinetixPageHeader` and `KinetixInfolistEntries`; standalone use keeps the
  internal confirm-and-run path.
- `--team` record pages no longer 404: under `Route::prefix('{current_team}')`
  the team segment landed in `$record`; team-mode signatures now lead with
  `string $current_team`.

## [0.145.0] - 2026-08-05

- BelongsToMany attach/detach for relation managers: `AttachAction::make()`
  (toolbar) opens a modal of not-yet-attached records — searchable on the new
  `protected static ?string $recordTitleAttribute`, capped at 50 — attaching
  via `syncWithoutDetaching`; `DetachAction::make()` (row/bulk) confirms and
  removes pivot rows only. Auto-wired via a signed, user-bound, expiring
  descriptor; new team-prefixed endpoints
  (`tables/relations/attachable|attach|detach`) require `update` on the
  PARENT when it has a policy; attach/detach on a non-BelongsToMany relation
  throws at serialize time. New i18n keys in all 7 locales.
- `protected static bool $readOnly` renders the manager's table with no
  record/toolbar/bulk actions.
- **(published)** `kinetixRoutePrefix` no longer throws outside a mounted
  Inertia app — it falls back to the default prefix.

## [0.144.0] - 2026-08-05

- **(published)** `<KinetixRelationManagers>` — the relation managers host
  with automatic tabs: several managers render as a tab each (title +
  optional badge via `getBadge()` / `$badgeColor`), one renders plain;
  `:tabs="false"` forces stacked. `canViewForRecord(Model $parent, string
  $page)` gives record/user-aware visibility; `getRelation()` exposes the
  relationship object; `$title` passes through `__()`.
  `kinetix:make-resource` wires managers end to end on show/edit pages.
- Fixed cross-team record access in scaffolded controllers: record routes now
  resolve through `Resource::getEloquentQuery()->findOrFail($record)`
  (out-of-scope ids 404). **Existing scaffolded apps: apply the same pattern
  manually** — see docs/relation-managers.md.
- Table write endpoints now INTERSECT the resource query with the captured
  scope (no widening); `recordModals()` inside a relation manager is rejected
  with a clear exception — use row actions instead.
- Published agent skills link to the hosted docs site instead of local
  filesystem paths.

## [0.143.0] - 2026-08-05

- **(published)** `MonthPicker`, `WeekPicker` and `YearPicker` join the picker
  contract shared by all seven date/time pickers: `->confirm()` (draft +
  Apply-only commit), `->todayButton()` (This month / This week / This year,
  new i18n keys in 7 locales, repositions the view), `->closeOnSelect(bool)`,
  and `->timezone(string)` (per-field → `app.timezone` → browser). Same props
  on the standalone Vue components; `<KinetixWeekCalendar>` gains `timezone`.
  Re-publish components.
- README + docs landing add a "Built with Kinetix" showcase (WebhookCatcher,
  Pokkeri) and support links.

## [0.142.0] - 2026-08-05

- **(published)** `DateRangePicker` reaches full picker parity: `->confirm()`
  (draft + Apply-only commit), `->todayButton()` (`from = to = today`),
  `->closeOnSelect(bool)` (default `true`), `->timezone(string)`; same props
  on `<KinetixDateRangePicker>`, a `timezone` prop on
  `<KinetixRangeCalendar>`, and `touch-manipulation` on the trigger.
  Re-publish components.

## [0.141.0] - 2026-08-05

- **(published)** `DateTimePicker`/`TimePicker` popovers gain a footer:
  **Done** (explicit dismissal) and **Now** (current date/time rounded to
  `minuteStep`, carrying overflow into hour/date).
- `->confirm()` explicit-commit variant on `DatePicker`, `DateTimePicker` and
  `TimePicker` (and a `confirm` Vue prop): clicks build a draft, the footer's
  Apply is the only commit, any other dismissal discards. Also
  `DatePicker->todayButton()` and `->closeOnSelect(false)`.
- Timezone correctness: Today/Now presets and initial views read the clock in
  the effective timezone — per-field `->timezone()` → `app.timezone` (shared
  via `kinetix_config.timezone`) → browser — through new
  `zonedNow`/`zonedTodayIso` helpers (`useKinetixTimezone.ts`, Intl-based,
  DST-correct). Pickers never convert values between zones (documented in
  Forms → Timezones).
- **(published)** Mobile touch targets + a11y on the time columns: ~40px rows
  on mobile, `touch-manipulation`, `80dvh` panel cap with internal scroll,
  `aria-label`s and `aria-pressed` on options. Re-publish components.

## [0.140.0] - 2026-08-05

- `AssignableRoles` — DB-backed allow-list resolver: point
  `kinetix.membership.assignable_roles` at
  `Happones\Kinetix\Permissions\AssignableRoles::class` and the invite picker
  offers the team's own roles PLUS global (team-NULL) ones, minus protected —
  matching the Roles UI; `::names($teamId, except: [...])` and
  `::query($teamId)` for customization.
- **(published)** Super-admins can create GLOBAL roles from the role editor
  (anyone else sending `global: true` gets 403); deleting a role in use is
  blocked (422) with the member count.
- Fixed: `usersCount` no longer leaks cross-tenant totals; the permission
  guard checks the DELTA both directions (added AND removed abilities); role
  changes/resends on a REVOKED provision rejected (422); role assignment pins
  spatie's team id whenever `permission.teams` is on (`kinetix:doctor` warns
  when membership and permissions team flags disagree); **(published)**
  `KinetixRoleManager` honors `isGlobal`; **(published)** `useKinetixCan` no
  longer throws outside a mounted Inertia app (denies instead).
- **(published)** `KinetixMemberList` reaches the package UI standard
  (per-row pending spinners, Remove confirmation, aria-labels, search,
  skeleton, async role default); docs cover the full roles journey (spatie
  install, first-admin bootstrap, server-side enforcement) and membership
  mail prerequisites (the activation notification is `ShouldQueue` — without
  a worker no invite is ever sent).

## [0.139.0] - 2026-08-05

- **(published)** Popovers opened inside a sheet or modal no longer render
  behind it: all body-teleported layers now sit on a documented CSS-variable
  z-index scale with inlined fallbacks — `--kinetix-z-overlay` (100),
  `--kinetix-z-modal` (100), `--kinetix-z-popover` (120, above modals) —
  overridable on `:root`; a source-scan test fails any component hardcoding
  `z-50`/`z-[100]`. Documented in Installation → Z-index scale. Re-publish
  components.

## [0.138.1] - 2026-08-04

- CI fix: the v0.138.0 notification tests pin `queue.default=sync` under the
  testbench runner, and the pre-push hook now also runs `composer test:ci`
  and `npm run test:unit` alongside vue-tsc + PHPStan.

## [0.138.0] - 2026-08-04

- Import/export notification lifecycle, automatic and customizable: a queued
  toast (`getStartedNotificationBody()`), a completion database notification
  (broadcast live when Echo is configured) via
  `getCompletedNotificationTitle/Body(int $done, int $failed)`, and a
  `danger` notification on exhausted retries. Exports now SKIP records whose
  `mapRecord()` throws (notification downgrades to `warning` with the count);
  failed import rows are downloadable as a full CSV behind a signed,
  user-bound, expiring token. New i18n keys in all 7 locales.
- **(published)** Notifications polling without Echo:
  `<KinetixNotifications />` polls via partial reload every
  `kinetix.notifications.poll` ms (default `30000`, `0` disables; pauses in
  background tabs); a genuinely new unread item toasts + sounds, broadcast
  arrivals are deduplicated. Re-publish components, stores and types.
- Team-scoped notifications: `kinetix.notifications.teams` tri-state
  (`true`/`false` win, `null` inherits `kinetix.teams`);
  `Notification::team($key)` stamps a team key (import/export jobs capture it
  automatically); the bell lists the active team's plus global ones;
  other-team broadcasts are suppressed client-side (new
  `kinetix_config.team_id`).
- **(published)** `KinetixButton` — shared base button with a pending state
  (`loading` disables, sets `aria-busy`, swaps icon for a spinner); table
  toolbar/footer and page-header actions render through it (`processingAction`
  on `useActionConfirmation()`); `kinetix:make-resource` pages scaffold it.
  `@laravel/echo-vue` is now a core install dependency (`--broadcasting` is a
  deprecated no-op); installation docs list every mount-once host and Vue
  plugin registration.

## [0.137.0] - 2026-08-03

- `make-resource` pages no longer double-wrap the form in two cards:
  full-mode resources scaffold `Section::make(__('Details'))` around the
  fields (one card); `--simple` keeps bare fields since the record modal is
  the surface; the mobile action row is full-width. Existing scaffolded apps
  untouched.

## [0.136.0] - 2026-08-03

- `make-resource` Create/Edit/Show pages scaffold an on-theme, mobile-first
  layout: `KinetixPageHeader`, responsive measure and padding, shared
  `buttonVariants()` buttons with a saving/disabled state, full-width stacked
  mobile buttons. Existing scaffolded pages untouched.
- **(published)** Product-tour popovers follow the active theme (dark mode
  included): styles now ship with `<KinetixTours />` and resolve through
  Tailwind-level `--color-*` variables, working on both token conventions and
  tracking `html.dark` live.
- **(published)** Infolist grids joined the responsive system: `columns(int)`
  means N columns from `lg` up and one below; breakpoint maps on `Infolist`,
  `Section`, `Fieldset`, `Grid` and `Tab`; container-query breakpoints and
  per-breakpoint span clamping; grid CSS moved to a shared `kinetix-grid.css`
  imported by forms and infolists.

## [0.135.0] - 2026-08-03

- **(published)** Form grids are responsive: `columns(int)` now means "N
  columns from `lg` up, ONE below"; `columns()`/`columnSpan()` accept
  breakpoint maps (`['default' => 1, 'sm' => 2, 'xl' => 3]`) on Grid,
  Section, Fieldset, Tab and wizard Step; breakpoints measure the form's OWN
  width via CSS container queries, and spans clamp so they never overflow.
  New `useKinetixResponsiveGrid` composable.
- **(published)** `Table::toolbarLayout('auto'|'inline'|'stacked')` — the
  default `auto` adapts to the table's own width (narrow stacks, wide
  inlines); pagination's page label no longer wraps mid-phrase.
- **(published)** Member-list row controls no longer overflow the card on
  narrow widths.

## [0.134.0] - 2026-08-03

- **(published)** The Tiptap rich-editor driver could never load in a
  production build (`@vite-ignore` bare imports never resolve in the browser)
  — the engine is now host-registered: call `registerKinetixTiptap()` in the
  app entry with your own dynamic imports (statically resolved by the host
  build, still code-split). New `useKinetixRichEditorEngine` composable;
  updated install notice in 7 locales.
- **(published)** Migrations no longer hardcode `unsignedBigInteger` for
  columns referencing HOST models: all 21 affected migrations type
  `user_id`/`team_id`/morph columns via `Happones\Kinetix\Support\HostKeys`
  at migrate time (ULID/UUID/string `$keyType` detected, else bigint); new
  `kinetix.key_types` config pins a type when detection can't (morph ids
  follow `kinetix.key_types.morph`, default bigint). Apps already migrated on
  bigint need their own `ALTER` path.
- `@tanstack/vue-virtual` is now a required peer and core `kinetix:install`
  dependency (`--tanstack` now covers only `@tanstack/vue-table`).
- `kinetix:upgrade` no longer reports upstream changes as "local edits": each
  publish records a hash manifest
  (`storage/app/kinetix-published-manifest.json`) and drift compares disk
  against what the last publish wrote. **(published)** Both membership role
  selects render the same labels (shared `roleLabel` in `useKinetixMembers`).

## [0.133.0] - 2026-08-03

- **(published)** UUID/ULID host-model guidance for humans and agents: which
  host-referencing columns to retype before migrating, per feature and
  publish tag (including the spatie-pivot exception), with the ALTER path for
  already-migrated apps — in the installation docs, the `kinetix-boost`
  skill, and each of the 18 affected feature skills.
- **(published)** Every drag-and-drop surface gains a keyboard alternative:
  reorderable table rows (focusable grip, Arrow Up/Down, announced moves,
  debounced persistence), kanban cards (Arrow Left/Right with announcement
  and an `sr-only` hint, focus follows the card), and repeater item buttons
  gain translated `aria-label`s and focus rings. New keys in all 7 locales.
- **(published)** Table result counts are announced to screen readers through
  the shared polite live region (`useKinetixAnnounce`) on
  search/filter/sort/page changes; `poll()` refreshes stay silent. New
  `results_count` key in all 7 locales.

## [0.132.0] - 2026-08-03

- **(published)** v0.131.0's chart axis/grid theming silently no-oped on
  complete-color hosts (double-wrapped `hsl(hsl(…))` was dropped by the
  browser): the eight surface properties now resolve in JS through the
  both-shapes token normalizer, bound as inline style and re-resolved on
  dark-mode changes. Documented that a stock theme defines only
  `--chart-1`…`--chart-5` — series 6-8 fall back to Kinetix's palette.
- **(published)** Tables & Forms accessibility pass: `aria-sort` on sortable
  headers; translated `aria-label`s on selection checkboxes and icon-only
  pager buttons; pagination in a labeled `<nav>`; errored fields set
  `aria-invalid` + `aria-describedby` pointing at a `role="alert"` error with
  a stable `<name>-error` id, wired via fallthrough attrs to all ~30 field
  types. New i18n keys in all 7 locales.

## [0.131.0] - 2026-08-03

- **(published)** Chart fixes: tooltips never rendered (XY now goes through
  the crosshair with per-series dots at visual height; the donut binds a
  per-segment trigger and shows the slice's share); axis/grid styling now
  flows through unovis' CSS custom properties mapped to theme tokens;
  stacked-area line overlays use cumulative accessors; per-slice
  `backgroundColor` arrays win over the palette; `useKinetixIcons` gains
  `book`, `chart-bar`, `trending-down` and more.
- **(published)** Chart legends default to AUTO: shown whenever the chart has
  two or more series; `legend(false)` forces off, `legend()` forces on.
  Legend entries toggle their series (colors stay keyed to the original
  index; the last visible entry can't be hidden).
- **(published)** Theme-token chart palette from `--chart-1`…`--chart-8` with
  separately tuned light/dark steps, validated for adjacent-pair colorblind
  separation and ≥3:1 contrast; `useKinetixChartPalette` resolves at runtime
  (HSL-triplet or complete-color values) and re-resolves when `html.dark`
  toggles; dataset colors still win; entrance animations respect
  `prefers-reduced-motion`.
- **(published)** `KinetixSparkline` shared component (`currentColor` tinting,
  per-instance gradient IDs, reduced-motion aware) used by stats widgets and
  table stat cards; `TableStat` gains `descriptionIcon()`,
  `descriptionColor()` and `chart([...])`. Tooling: husky pre-commit
  (lint-staged), commit-msg (commitlint) and pre-push (vue-tsc + phpstan)
  hooks.

## [0.130.1] - 2026-08-03

- Patch over v0.130.0 (red pipeline — **upgrade if you installed v0.130.0**):
  **(published)** `types/kinetix.ts` declared `KinetixChartDataset` twice
  (now one superset declaration); `Table::getResolvedQuery()` no longer
  mutates the builder passed to `Table::make()` (resolves onto a fresh
  builder); `composer test:ci` and `npm run types:check` mirror the CI
  commands so local green predicts pipeline green.

## [0.130.0] - 2026-08-03

- **(published)** `Table::stats()` — KPI cards above a table:
  `TableStat::make('Total')->count()/sum()/avg()/min()/max()` with
  `where()`/`whereNull()`/`whereNotNull()` conditions, `icon()`, `color()`,
  `description()`, `url()`, formatting helpers (`numeric()`, `money()`,
  `prefix()`, `suffix()`) and `visible()/hidden()/can()` (an invisible card
  is never computed). Every card compiles into a conditional aggregate inside
  ONE shared query; cards follow the table's active filters over the whole
  filtered set (`ignoreFilters()` opts out and shares a second query;
  `using()` is the explicit one-query escape hatch). Available on Resources
  via `Resource::table()`.
- **(published)** `KinetixLanguageSwitcher` gains a `variant` prop:
  `dropdown` (default) or `select` (built on `KinetixSelect`, with `label`
  and `:show-label="false"`).
- Numeric/money formatting shared via a `FormatsAggregateValue` concern;
  `KinetixTable`'s root is now a wrapper holding stat cards + the table card
  (attrs still forwarded to the table card); two language switchers on one
  page stay in sync — `current` is now derived from vue-i18n's locale
  (per-app, still per-request under SSR).

## [0.129.0] - 2026-08-03

- **Security:** inline cell edits/reorder now fully authorized (table-scoped record resolution, policy or `Table::writeAbility()`, user-bound expiring descriptors — `kinetix.tables.token_ttl`, default 24h); signed descriptors user-bound and expiring across tables, kanban, searchable Selects and TableRepeater; exports/imports gated by `Exporter::authorize()` / `Importer::authorize()`; generated artifacts (exports, import files, report runs, GDPR dumps) moved to `kinetix.filesystem.private_disk` (default `local`); download links recipient-bound and expiring (`kinetix.exports.download_ttl`); CSV/XLSX formula injection neutralized; uploads reject browser-executable files (`kinetix.filesystem.upload_blocked_extensions`, `upload_max_size`) and are stored per user (`kinetix.filesystem.scope_uploads_by_user`, default on); membership provision admin endpoints team-scoped; impersonation cannot be laundered into role management (`kinetix.impersonation.protected_permissions`); `Select::searchScope()` bounds remote search; `perPage` clamped (`kinetix.tables.max_per_page`); LIKE escaping in `AddressFilter`; PDF colour validation; `MediaManager::sync()` path constraints.
- Fixed: `php artisan route:cache` works (closure routes converted to controllers); the `kinetix-components` publish tag now includes `resources/js/icons` and `resources/js/plugins` so the published frontend builds; `KinetixConfirmModal` / `KinetixSheet` gain focus trap, initial focus, restore and `aria-labelledby` (`useKinetixFocusTrap`); kanban column virtualization bug (`virtual.enabled` read without `.value`); queued exports/imports/GDPR dumps declare `tries`/`backoff`/`failed()` and report failures; `validateEnvironment()` no longer runs per production request; missing-dependency guards for spatie/laravel-permission and laravel/socialite; stray timers cleared on unmount.
- **Breaking (published):** vendor-managed files renamed to never claim a host filename — `stores/kinetixNotifications.ts`, `stores/kinetixTours.ts`, `useKinetixMasonryColumns` / `useKinetixShadcnVariants` / `useKinetixStatusColor`, `icons/kinetixBrands*`. Update your imports.
- Misc: `KinetixTableCell` uses a component map; MediaLibrary grid virtualizes past a threshold; widgets serialize through `WidgetData` (generated TS contract); endpoint messages translated in 7 locales; slimmer Packagist dist via `.gitattributes`; `composer.json` `suggest` lists the optional packages the code guards for.

## [0.128.0] - 2026-08-03

- **Breaking:** form grid defaults changed — a field's default `columnSpan` is now **1** (was `'full'`); form root is 1 column, `Grid::make()` is 2, `Section`/`Fieldset`/`Tab`/`Step` are 1 (all were 12). Simple forms render identically; a field with no explicit span inside an explicit `Grid::make(12)` (or `->columns(12)`) now takes 1/12 — add `->columnSpanFull()` or drop the explicit count. Explicit spans inside explicit grids are unaffected; infolists keep their 12-column system.

## [0.127.0] - 2026-08-02

- `Select::relationship($name, $titleColumn, $modifyQueryUsing)` on form fields, inherited by `CheckboxList` and `Radio`; `Form` hands its model to relationship-aware fields (`ResolvesRelationships` contract), falling back to `options()` without one.
- `relationship()` composes with `searchable()` — the remote-search token derives from the relation; the query modifier travels in the token only as the class-string of an invokable class (closures are not serializable there).
- New config `kinetix.forms.relationship_options_limit` (default 200); `SelectFilter::relationship()` options are now capped and log a warning when truncated instead of loading every related row.

## [0.126.0] - 2026-08-02

- Column summaries now share a single aggregate query (`sum`/`avg`/`min`/`max`/`count` folded into one scan) instead of one query per summarizer; summarizers with a `query()` scope or `using()` callback keep their own query.
- New `Summarizer::aggregateExpressions()` / `summarizeFromValues()` / `isBatchable()` — the contract for custom summarizers to join the shared scan; not implementing it just keeps the per-summarizer query.

## [0.125.0] - 2026-08-02

- `Table::cursorPaginated()` — seek-based pagination (`WHERE (sort, id) > (…)`) so deep pages cost the same; the primary key is auto-appended to the sort as a tiebreaker; sorts a cursor cannot encode (relation columns, custom `sortable(using:)`) fall back to `simplePaginated()` for that request.
- Payload adds `pagination.nextCursor` / `prevCursor` / `onFirstPage`; the reload contract gains `cursor` (mutually exclusive with `page`, dropped when search/sort/filters/page size change).
- **Breaking:** `TablePaginationData.currentPage`, `from` and `to` are nullable (null in cursor mode); the bundled footer renders prev/next only there. `paginated()` tables unaffected.

## [0.124.0] - 2026-08-02

- `Table::simplePaginated()` — pagination without the `COUNT(*)` (fetches one extra row to detect a next page); `pagination.hasMore` added to the payload and to `useKinetixClientTable` as the mode-independent next-page signal.
- **Breaking:** `TablePaginationData.total` and `.lastPage` are nullable (null in simple mode) — guard custom footer reads before adopting; the bundled footer drops the total line and first/last jumps in simple mode.
- Pagination buttons carry `data-testid` (`page-first`/`page-prev`/`page-next`/`page-last`); new `page_number` and `showing_range` strings in all 7 locales.

## [0.123.0] - 2026-08-02

- Fixed: dot-notation table columns (`author.name`) now derive eager loads from the declared columns — no more per-row N+1; LIKE wildcards in search input escaped with an explicit `ESCAPE '!'` clause; `kinetix:make-resource` scaffolds `KinetixTeams::currentTeamKey()` instead of `request()->user()->currentTeam->id`.
- New `Happones\Kinetix\Query\KinetixQuery` — shared query primitives (`search()`, `escapeLike()`, `eagerLoad()`, `sortByRelation()`, `direction()`) used by `Table`, select-field search, Spotlight and the API-log/webhook-log feeds; tenancy stays the caller's job via an already-scoped base query (`Resource::getEloquentQuery()`), and grouped OR search terms cannot escape the tenant filter.

## [0.122.0] - 2026-08-02

- API request logs are tenant-aware: rows stamped with the caller's team (URL segment, else the token holder's `currentTeam`), viewer strictly scoped; NULL rows are unattributed and fail closed (prune with `kinetix:api-logs:prune`). `kinetix:doctor` updated for the newly scoped modules.
- Upgrade: `php artisan vendor:publish --tag=kinetix-api-logs-migrations --force` then `php artisan migrate` — additive and idempotent; single-tenant apps unaffected.

## [0.121.0] - 2026-08-02

- Mail Templates are tenant-aware: `team_id` NULL is a global default, teams hold overrides under the same key (uniqueness now `(team_id, key)`); editing a global template from a team forks it (copy-on-write), deleting the fork reverts, deleting the default from a team is refused.
- Announcements are tenant-aware; `KinetixAnnouncements::publishGlobally()` reaches every tenant, and NULL `team_id` entries are platform-wide.
- `ScopedToTeam` gains `->forCurrentTeamOrGlobal()` and `::teamAttributes()` (writes `team_id` only while scoped); `kinetix:doctor` reports tables missing their `team_id` column. Fixed: mail-template edit/delete/test broken with teams on (route parameter now read by name).
- Upgrade: publish `kinetix-mail-templates-migrations` and `kinetix-announcements-migrations` with `--force`, then `migrate` — additive and idempotent; existing rows keep `team_id` NULL and become the global defaults.

## [0.120.0] - 2026-08-02

- **Security:** Reports Center is now tenant-isolated — schedules and runs are stamped with the active team and every query (including `findRun()`/`findSchedule()`) scoped; previously any `viewKinetixReportsCenter` holder could list, run, cancel, download and delete another team's reports.
- Fixed: Activity, Settings, Webhooks, Onboarding and Wizards resolve the tenant from the `{current_team}` URL segment via `KinetixTeams` (with membership check) instead of the user's stored `currentTeam`; a route-model-bound team no longer crashes the `kinetix_config` share (`getRouteKey()`); billing's `{team}` segment is recognized alongside `{current_team}`.
- Added `useKinetixTeams().teamUrl()` and `currentTeamKey` (route-key-based team links, backed by a new `kinetix_config.team` prop); `KinetixTeams::keyFor('module')` as the one tenant resolver; `ScopedToTeam` trait (`->forCurrentTeam()`, `::currentTeamId()`, fails closed); `kinetix:doctor` names enabled global-data modules while teams are on.
- Docs: team-scoping coverage table in `installation.md` (route prefixing ≠ data isolation); exports endpoints documented as deliberately outside the team segment.

## [0.119.0] - 2026-07-30

- **Security:** the team-owner gate bypass no longer short-circuits model policies — it grants only abilities registered in the `PermissionRegistry`; record-bound policy abilities fall through to your policy. `PermissionRegistry::has()` is public for hand-written bypasses.
- **Breaking:** TypeScript declarations now publish to `resources/js/types/kinetix.ts` instead of `types/index.ts` (the app's own barrel, which the publish used to overwrite). Migration: restore your barrel and import Kinetix types from `@/types/kinetix`; `kinetix:upgrade` and `kinetix:doctor` flag a leftover Kinetix-authored `index.ts`.
- Config callbacks accept a `[ClassName::class, 'method']` callable array (container-resolved) — closures in config break `php artisan config:cache`.
- New `php artisan kinetix:doctor` — checks route prefix, module state, duplicated `kinetix.*` route names, half-enabled team scoping, teamless roles, missing `attach_member`, closures in config, duplicated i18n bundles, legacy `types/index.ts`, locally edited published files; non-zero exit, `--json`. Also: `kinetix:upgrade` lists local edits it overwrites; duplicated vue-i18n bundle detection; `kinetix:install` gitignores regenerated output.

## [0.118.0] - 2026-07-30

- New `php artisan kinetix:routes` — lists every registered Kinetix endpoint with resolved URI, name and (`-v`) middleware; `--json`, optional filter; app routes under the same prefix shown too.
- Team-owner gate bypass via config: `kinetix.permissions.owner_bypass` (`true` uses the host's `$user->ownsTeam($team)`, or a callback / invokable class-string); `membership.assignable_roles` accepts a callback receiving the team key; callback config options also accept invokable class-strings to keep `config:cache` working.
- New `vendor:publish --tag=kinetix-skills` publishes the 46 bundled agent skills; `kinetix:install` publishes them by default (`--skip-skills` opts out), `kinetix:upgrade` refreshes, `kinetix.skills_path` retargets (default `.claude/skills`).
- Diagnostics: `kinetix:permissions:sync` lists unprotected global (teamless) roles; boot warning when membership team scoping is on but `attach_member` is null; local-only warning when a host `share()` overwrites the `kinetix_*` Inertia props. Skills and docs now lead with common integration mistakes and the endpoint-prefix contract.

## [0.117.0] - 2026-07-25

- New Product Tours module: backend-declared, permission-aware guided tours (`KinetixTours::tour('posts')->page(...)->permission(...)->steps([TourStep::make(...)])`) shared via the `kinetix_tours` Inertia prop, permission-filtered server-side, with page/url wildcard matching and `auto(false)`.
- One global `<KinetixTours />` host auto-starts the unseen matching tour; driver.js is an opt-in host dependency (`kinetix:install --tours`), lazy-imported and themed by the published `kinetix.css` (light + dark).
- Seen-state drivers: `local` (localStorage, default) or `database` (per-user table, publish `kinetix-tours-migrations`); `useKinetixToursStore` for manual control (`start(id)`, `hasSeen`/`markSeen`/`reset`).
- New `tour_*` strings in all 7 locales; `docs/tours.md`; the dependency-free `useKinetixTour`/`<KinetixTour>` remains for hand-mounted cases.

## [0.116.1] - 2026-07-25

- `kinetix:upgrade` now forwards `kinetix.translations.vue_i18n_options` so multi-locale vue-i18n bundles are the ones recompiled (set `['--multi-locales' => true]` there), and `kinetix:install` appends the vendor-managed publish paths to `.prettierignore` (plus prints the eslint `ignores` equivalent) so host formatters stop churning published copies — existing installs re-run `kinetix:install`.

## [0.116.0] - 2026-07-24

- Permission shapes beyond CRUD: `Feature::access()` for access-only modules (a single `{feature}.access` ability as the matrix's first column); custom in-module abilities render inside their module's expandable row (the header vocabulary stays fixed); `Feature::group('HR')` adds titled sections (`PermissionFeatureData.group`).
- `->can('feature.ability')` server-side gating **(published trait)** for form fields, infolist entries, table columns and actions — evaluated at serialization; a denied component is stripped from the schema, validation rules, submitted state, infolist and table payloads, so gated data never leaves the server.
- New `access` / `role_custom_abilities` strings in all 7 locales; permissions docs cover the shapes and the can-vs-authorize distinction.

## [0.115.1] - 2026-07-24

- **Security:** role management is tenant-isolated under team scoping — listings and mutations scoped to the configured guard plus current team and global (team-NULL) roles; foreign roles 404, global roles are super-admin-only, duplicate names are a 422 instead of a silent `findOrCreate` takeover; permission team context follows the URL's team via `KinetixTeams::currentTeamKey()`; `RoleData.isGlobal` added and the role UIs badge global roles; the `kinetix_permissions` share is cheaper per request; matrix column headers now render generic translated CRUD labels **(published)** with new `view_any`/`delete_any` strings in 7 locales.

## [0.115.0] - 2026-07-23

- New Help Center module: in-app manual rendered from host-owned markdown (`kinetix.help.path`, default `resources/help`); front matter (`title`/`group`/`icon`/`order`/`permission`), locale variants with regional fallback; permission-aware server-side (hidden from index/search, 404 on direct access) plus `<!-- kinetix:can ability -->` block stripping; hardened rendering, metadata-only cache (`kinetix.help.cache`).
- Screenshots via `php artisan kinetix:help-screenshots` driving a publishable Playwright runner (`--tag=kinetix-help-screenshots`) with configurable disk (private disks stream through the authenticated endpoint).
- **(published)** `<KinetixHelpCenter>` (grouped/list views, debounced server-side search) and `<KinetixHelpArticle>` (sanitized HTML, TOC, prev/next), plus `useKinetixHelp()` / `useKinetixHelpToc()`; help articles surface in Spotlight; `kinetix:make-help-page` scaffold; 11 new strings in 7 locales; `docs/help-center.md`.

## [0.114.0] - 2026-07-22

- Plan-gating suite for the frontend **(published)**: the billable's current plan is shared as the `kinetix_billing` Inertia prop; `useKinetixPlan()` (`plan`/`onPlan`/`featureValue`/`canUseFeature`/`hasReachedLimit`/`remaining`) and `<KinetixPlanFeature>` (capability mode `feature="..."`, usage-limit mode `limit="..." :count`, both with a `#denied` slot). Display gating only — the server still enforces via `plan.feature` middleware / `HasPlan`.
- `Plan::remainingLimit()` / `HasPlan::remainingPlanLimit()` — units left before a usage limit (null = unlimited); billing docs cover the helpers and UI gating.

## [0.113.0] - 2026-07-21

- `KinetixRolesOverview` **(published)** — read-only roles-and-permissions matrix (role cards with member counts, per-module rows, full/partial/none cells, sticky header and module column); create/edit reuse the same editor modal as `KinetixRoleMatrix`. New `kinetix:make-roles-page` scaffold behind `roles.manage`; new locale strings in 7 languages.
- Sticky role-matrix editor **(published)**: matrix scrolls in its own container with sticky header row and module column; accessible row toggles (`aria-pressed`, `aria-label`); editor and delete dialogs extracted to reusable `Roles/KinetixRoleEditorModal.vue` / `Roles/KinetixRoleDeleteDialog.vue` — re-publish components.
- Fixed: ESLint crashed with ELOOP following testbench's circular vendor symlink; lint scripts now remove it defensively and accumulated style errors are fixed.

## [0.112.1] - 2026-07-21

- Fixed: the tables/imports/uploads/notifications route groups under `{current_team}` now carry the `kinetix.permissions.team` bridge middleware, so policy checks on them evaluate with team context (they previously denied users whose roles are team-scoped); export endpoints remain the documented exception (token-signed URLs built inside queued jobs), and billing keeps its own `kinetix.billing.teams` flag and `{team}` parameter.

## [0.112.0] - 2026-07-21

- `Resource::getUrl(operation, ?record)` is public API — builds team-aware URLs for resource operations, auto-filling the record's route key and the `{current_team}` segment.
- Fixed: the full-mode scaffold works under team-scoped routes — generated controllers pass server-resolved `storeUrl`/`updateUrl`/`cancelUrl` props and the Create/Edit pages consume them (the Edit page's `recordId` prop is gone; re-scaffold or update customized pages).
- Fixed **(published)**: simple-resource modals clear stale validation errors on open, and closing drops the active form/infolist DTO so nothing stale flashes on reopen.

## [0.111.3] - 2026-07-20

- Fixed **(published)**: role management no longer 403s a team owner whose permissions come from `Gate::before` — `assertCanGrant()` resolves "held" through the Gate, and the `kinetix_permissions` frontend `can()` map now includes Gate-granted registered abilities (prop shape unchanged); docs added on localizing schema labels via `__()` and on the starter-kit wide-table `min-w-0` fix.

## [0.111.1] - 2026-07-17

- Fixed **(published)**: wide tables no longer overflow the viewport in flex layouts — `KinetixTable`'s card carries `min-w-0 max-w-full` and the scaffolded page wrapper adds `min-w-0`, so a too-wide table scrolls locally.

## [0.111.0] - 2026-07-17

- Scaffold polish **(published)**: `kinetix:make-resource` wraps every generated page in a consistent flex container and groups row actions into a "⋯" dropdown (`ActionGroup::make([...])`) by default.
- Fixed: `ActionGroup` is dropped from a row's payload when every child action is hidden/unauthorized, instead of rendering an empty dropdown.

## [0.110.0] - 2026-07-17

- New `Action::route(string $name, array $params = [], string $method = 'get')` — wires actions to named routes (per-record or record-less), fills the `{current_team}` segment, and auto-hides when the route isn't registered; non-`get` methods perform an Inertia visit.
- Generated resources declare their table actions on the resource **(published)** — controllers are thin; full-mode actions use `->route()` (self-hiding), simple-mode use `->modal()`; the full-mode "New" button now comes from the table's self-hiding `CreateAction`.

## [0.109.0] - 2026-07-17

- Full resources scaffold a `show` page **(published)**: `show()` controller method, per-row `ViewAction`, and a `Show.vue` pairing the resource's `infolist()` with a `KinetixPageHeader` carrying Edit/Delete actions.
- New redirect hooks `Resource::getRedirectUrlAfterCreate(Model)` (default index) and `getRedirectUrlAfterSave(Model)` (default stay on edit); override with `static::resolveHref('index'|'edit'|'show', $record)`.
- Simple-resource record modals teleport to `<body>` **(published)**, so overlays are never clipped by the table's container.

## [0.108.1] - 2026-07-17

- Fixed **(published)**: `vue-tsc` type error in `useKinetixRecordModals` — the active form ref is `any` and the infolist ref is typed `KinetixInfolistData`, satisfying the `KinetixForm`/`KinetixInfolist` prop types.

## [0.108.0] - 2026-07-17

- Kinetix-owned in-table modal CRUD for simple resources **(published)**: a `--simple` page is literally `<KinetixTable :table>` with create/edit/view/reorder/delete — opt in with `Table::recordModals(Resource::class)` and `Action::modal('create'|'edit'|'view'|'delete')`; CRUD runs through a signed record endpoint (`_kinetix/tables/record` + `/resolve`) guarded by an encrypted token and the model's policy.
- Fresh-record-on-edit by default **(published)**; configure via new `kinetix.tables.record_source` (`server` default, `row`) or per table with `->recordModals(Resource::class, source: 'row')`.
- New resource hooks `getEloquentQuery()` and `mutateFormDataBeforeSave()`; `kinetix:make-resource --reorderable`; `record_created`/`record_updated`/`record_deleted` strings in all 7 locales **(published)**.
- The `--simple` scaffold is rewritten **(published)**: index-only controller, scaffolded `infolist()`, team overrides with `--team`; regenerate with `kinetix:make-resource {Model} --simple` (a simple resource now needs only its `index` GET route).

## [0.107.1] - 2026-07-17

- The confidential keyring migration is now publishable (`vendor:publish --tag=kinetix-confidential-migrations`); new `kinetix.tables.number_locale` config key for numeric column summaries; doc-vs-code corrections across the feature guides (install steps, component import paths, config defaults, widget CSS property names).

## [0.107.0] - 2026-07-17

- Fixed **(published)**: client-side tables now render toolbar/header actions (with the same in-flight `:disabled` guard as server-driven tables).
- Per-row actions carry their record into the action event **(published)**: `executeAction` receives the row, so `dispatchEvent` actions get `CustomEvent.detail.record` and `inertiaVisit`/`httpRequest` bodies include it.
- `make-resource --simple` scaffolds event-driven modal CRUD: Create/Edit dispatch `kinetix:{slug}-create`/`-edit` browser events and the generated `Index.vue` opens its in-page modal (edit prefilled from the clicked row); replaces the v0.106.0 `?edit` partial-visit approach.

## [0.106.1] - 2026-07-17

- `kinetix:make-resource` full multi-page mode now scaffolds per-row `EditAction` (navigates to the edit page) and `DeleteAction` (confirm then `DELETE`) — previously the full-mode table had no row actions; regenerate to pick this up.

## [0.106.0] - 2026-07-17

- Fixed: `kinetix:make-resource --simple` scaffolds a working modal CRUD — the generated controller attaches per-row `EditAction` (re-renders the index with `?edit={id}` via an Inertia partial visit, opening the modal prefilled) and `DeleteAction` (confirm then `DELETE`); the controller passes an `editRecord` prop that `Index.vue` consumes. Regenerate simple resources to pick this up.

## [0.105.0] - 2026-07-16

- Actions can no longer be double-submitted: `executeAction` is async, `useActionConfirmation` tracks `processing`, buttons disable and the confirm modal stays open (spinner) until the request resolves — applies to record/toolbar/footer/bulk, dropdown, page header, infolist and calendar-event actions. **(published)**
- Failed background actions surface the server's error message via new `action_failed` translation key (all locales).
- New-tab actions open with `noopener,noreferrer` (reverse-tabnabbing fix). **(published)**
- `KinetixConfirmModal` gains a `processing` prop and no longer self-closes on confirm — if you render it directly, close it from your `@confirm` handler or bind `v-model:open`. **(published)**

## [0.104.1] - 2026-07-16

- Performance: memoized `SuperAdmin::check` (per user × team, WeakMap), cached `discoverResources` scan, one-query permission sync, O(1) role-matrix cells; new `useKinetixRoleEditor` composable dedupes role save/delete flows and `KinetixRoleManager` shows member counts. **(published)**

## [0.104.0] - 2026-07-16

- Role-management endpoints hardened against privilege escalation (super-admin bypasses all): submitted permission keys allowlisted against the registry (422), managers can only grant permissions they hold (403), and protected roles (new `permissions.protected_roles` config, default super-admin role) can't be created/renamed/edited/deleted; self-revocation of `roles.manage` rolls back (403). New shared `Permissions\SuperAdmin` class. **(published)**
- **Breaking:** previously any `roles.manage` holder could grant any permission — give role administrators the seeded `admin` role or super-admin to manage the full catalog.
- Super-admins no longer see permission-gated UI as denied: `kinetix_permissions` carries `isSuperAdmin`, honored by `can()`/`canAny()`/`canAll()`/`<KinetixCan>`; `useKinetixCan` uses a Set (O(1)). **(published)**

## [0.103.1] - 2026-07-16

- Fixes `vue-tsc` type errors from 0.103.0 (virtualization refs, virtual-row key, date-range filter cast, `buildTableQuery` payload type); adds `kinetix:install --tanstack` to install the virtualization peers (`@tanstack/vue-table`, `@tanstack/vue-virtual`).

## [0.103.0] - 2026-07-16

- Internal refactor of the five largest components (`KinetixTable`, `KinetixEventCalendar`, `KinetixFormSchema`, `KinetixWizard`, `KinetixIntegrationLogs`) into composables and subcomponents under `components/{Table,Calendar,Form,Wizard,IntegrationLogs}/`; `v-if` chains replaced by component maps, table rows gain `v-memo`. No public API change. **(published)** — re-publish with `php artisan vendor:publish --tag=kinetix-components --force`.
- Charts code-split: `@unovis/vue` is async-loaded via `widgets/UnovisChartCanvas.vue` only when a chart renders. **(published)**
- O(1) selection membership (Set instead of `includes()`) in role/permission matrices, checkbox list, token and webhook managers. **(published)**
- Threshold-gated list virtualization via new `useKinetixVirtualRows` (over `@tanstack/vue-virtual`, new optional peer), applied to `KinetixComments` and `KinetixKanban`; lists ≤ 40 rows render in full. **(published)**

## [0.102.0] - 2026-07-15

- Tables: sort by relationship columns (`TextColumn::make('author.name')->sortable()`) via correlated subquery (`BelongsTo`/`HasOne`), sort keys allowlisted; `->sortable(using: fn (Builder $q, string $dir) => ...)` for custom sorts.
- Tables: client-side rendering mode — `Table::make(...)->clientSide()` ships the capped (default 500) result set once; search/sort/pagination run in-browser via an async-loaded renderer (`KinetixDataTable`). `@tanstack/vue-table` is a new optional peer; server-driven tables never load it. **(published)**

## [0.101.0] - 2026-07-15

- Forms validation: `KinetixFormRequest` + `ResolvesKinetixForm` trait derive `rules()`/`messages()`/`attributes()` from the form schema; `dehydratedState()` returns the validated, dehydrated payload (namespace `Happones\Kinetix\Forms\Http`).
- Live validation via Precognition: opt in with `Form::precognitive()`/`Form::validationUrl()` plus the `HandlePrecognitiveRequests` middleware; ships a built-in client (`useKinetixPrecognition`) with zero new dependencies.
- Custom messages/attributes: `Field::validationMessages()`/`validationAttribute()`, `Form::messages()`/`validationAttributes()`; fields default `:attribute` to their label.
- Error focus in Tabs & Wizards: `KinetixForm` reads Inertia's `errors` bag automatically, marks/jumps to the first errored tab or step (navigable even under `linear`), focuses the first errored field; `KinetixWizard` gains `errorSteps`. **(published)**

## [0.99.0] - 2026-07-12

- Confidential Fields — field-level encryption, new namespace `Happones\Kinetix\Confidential` and config `kinetix.confidential`, zero new dependencies: `ConfidentialCast` encrypts at rest and masks on read everywhere (per-field `':<visible>,<head|tail>'` control); legacy plaintext reads safely and migrates via `php artisan kinetix:confidential:encrypt-existing`. **(published)**
- Reveal gate: `<KinetixConfidentialUnlock>` (zero props) prompts for the current password and opens a session-scoped reveal window (`reveal_ttl_minutes`, default 5); queued jobs stay masked by design.
- Key management: single current DEK in `kinetix_confidential_keys`, rotated via `php artisan kinetix:confidential:rotate-key` (old keys retained); unwrapped key cached (`key_cache_ttl_minutes`); zero-dependency local key manager wrapping via `APP_KEY`, custom KMS/Vault via the 2-method `KeyManager` interface.

## [0.98.0] - 2026-07-12

- Reports Center — queued, DB-tracked CSV/XLSX report generation; new namespace `Happones\Kinetix\ReportsCenter` and config `kinetix.reports_center` (independent from the email-only `kinetix.reports`). `Report` classes via `php artisan kinetix:make-report {name}`, auto-discovered in `app/Kinetix/Reports` (or `KinetixReportsCenter::register()`).
- Runs tracked in `kinetix_report_runs` (`pending → running → completed|failed|cancelled`) with per-chunk progress; cooperative cancellation works on every queue driver; retry dispatches a fresh run; downloads are disk-agnostic and expire (`php artisan kinetix:report-runs:prune`).
- Scheduling: `ReportSchedule` (`once`/`daily`/`weekly`/`monthly`) dispatched by `php artisan kinetix:report-schedules:dispatch-due` from the host's scheduler.
- Components: `<KinetixReportLauncher>`, `<KinetixReportRunsTable>`, `<KinetixReportSchedules>`, and the tabbed `<KinetixReportsCenter>` — all zero-prop, self-fetching, polling on `kinetix.reports_center.poll`. **(published)**

## [0.97.0] - 2026-07-12

- `<KinetixCookieConsent>` — accept/decline cookie consent bar; config-only (`kinetix.cookie_consent`: `enabled`, `cookie_name`, `expiry_days`, `position`, `policy_url`), shared as `kinetix_cookie_consent`, visibility resolved client-side via `useKinetixCookieConsent()` (plain browser cookie). **(published)**

## [0.96.0] - 2026-07-12

- Calendar: switching to `week`/`day` view auto-scrolls the hourly grid to the current time (no-op outside `startHour`/`endHour`).
- `Calendar::eventActions(array $actions)` — per-event actions resolved against each event's record like `Table::recordActions()`, using the shared `Action` builder/engine; rendered in both the details modal and sheet. `CalendarEventData` gains `actions` (default `[]`). **(published)**

## [0.95.0] - 2026-07-12

- `WidgetsGrid::masonry(int|array $columns = 3)` — column-balanced masonry layout (shortest-column placement, `columnSpan` ignored) via new `<KinetixMasonryColumns>` and `useMasonryColumns.ts`.
- `WidgetsGrid::gap(...)` (default `'1.5rem'`) and `->dense()` — both accept responsive breakpoint maps like `columns()`.
- New self-polling widget types `queue-stats` / `health-status` (`QueueStatsWidget`/`HealthStatusWidget`) to position and gate the live queue/health panels inside a grid; stats-overview cards now use CSS `@container` queries. **(published)**

## [0.94.1] - 2026-07-12

- `ProgressWidget::display()`/`caption()` now accept `?string` instead of rejecting `null`.

## [0.94.0] - 2026-07-12

- `<KinetixTimezonePicker>` — standalone searchable timezone combobox over all runtime-supported IANA zones (`Intl.supportedValuesOf`); props: `regions`, `display` (`name|offset|both`), `groupByRegion`, `showCurrentTime`, `clearable`. Docs: `docs/timezone-picker.md`. **(published)**

## [0.93.0] - 2026-07-12

- Calendar timezone support: `Calendar::timezone(string|Closure|null)` (default `app.timezone` via new `Support\KinetixTimezone`); events serialize as absolute ISO-8601 datetimes, re-renderable under any zone including a client `timezone` prop override; `CalendarEventData` gains `allDay` and `description` (`Calendar::description()`).
- Month/week/day views via new `views` prop (default `['month']`); week/day render an hourly grid with all-day banner and current-time indicator; `startHour`/`endHour`, `anchorDate`.
- Event details popup: built-in modal or new standalone `<KinetixSheet>` primitive (`event-display="sheet"`, `sheet-side`); `:show-event-details="false"` opts out. **(published)**
- **Breaking:** `CalendarEventData.start`/`.end` changed from `Y-m-d` date strings to full ISO-8601 datetimes with UTC offset — update any code reading `calendar.events[].start`/`.end` directly; `CalendarData` gains a required `timezone` field.

## [0.92.0] - 2026-07-11

- `<KinetixWizard>` `stepLayout` (`inline` (default) | `stacked` | `tooltip`, horizontal only) — `stacked` always shows labels, `tooltip` shows indicators only with hover/focus labels; PHP `Wizard::make()->stepLayout(...)`.
- Per-step `color` (`success|danger|warning|info|primary|gray`) accents active/complete indicators; upcoming steps stay neutral. Applies to both the standalone component and PHP `Wizard`/`Step` builders. **(published)**

## [0.91.2] - 2026-07-11

- `<KinetixUsageMeters>` (and the `kinetix:make-billing` scaffold) no longer blank the billing page when `usage` is missing — `metrics` is optional with a `[]` default and null-safe fallback. **(published)**

## [0.91.1] - 2026-07-11

- `<KinetixWizard>` horizontal stepper no longer overflows with many/long steps — the indicator scrolls internally (`overflow-x-auto`) and titles/descriptions truncate. **(published)**

## [0.91.0] - 2026-07-10

- Role/permission-gated Widgets, Form fields & Infolist entries: `Widgets\Widget` gains `->visible()`, `->hidden()`, `->authorize()` and `shouldRender()`; `WidgetsGrid::toArray()` filters unauthorized widgets before computing data (denied users never trigger the widget's query).
- Form and Infolist components adopt the shared `HasAuthorization` trait (`->authorize(string $ability, mixed $subject = null)`); record-dependent abilities defer until a record exists; unauthorized fields/entries are dropped from validation, hydration and the payload.

## [0.90.0] - 2026-07-10

- Metered-usage billing: `Billing\UsageMetric` fluent VO declared via the billable's `meteredUsage(?Plan $plan): array` (implementing `ProvidesUsageMetrics` optional); `BillingManager::usage()` resolves limits (explicit or plan `features.usage.{key}`), caps percent at 100, flags `overLimit`, threshold-based color overridable per metric.
- `BillingManager::reportUsage(int $quantity = 1, ?string $priceId = null)` wraps Cashier metered reporting.
- `<KinetixUsageMeters>` progress-bar card (renders nothing when empty), wired into the `kinetix:make-billing` scaffold; shared `statusFillClass()` in `useStatusColor.ts`; new i18n keys in all seven locales. **(published)**

## [0.89.0] - 2026-07-10

- Chinese (zh), Japanese (ja) and Russian (ru) translations — full catalogs; shipped locales now en, es, fr, pt, zh, ja, ru.
- Selective translation publishing: `kinetix.translations.locales` (array or `KINETIX_TRANSLATION_LOCALES=en,es`) controls which catalogs `--tag=kinetix-translations` copies and `kinetix:upgrade` refreshes.

## [0.88.2] - 2026-07-10

- New `ProvidesPdfData` contract — implement `toPdfData(): array` and pass the model directly to `KinetixPdf::render()/pdf()` (hybrid method detection; plain arrays keep working); docs cover the model setup end-to-end.

## [0.88.1] - 2026-07-10

- Webhook delivery-log knobs: `kinetix.webhooks.log_payloads` (default `true`) and `kinetix.webhooks.response_limit` (previously hardcoded 1000 chars), applied by both the native job and the spatie bridge; docs gain a delivery-log section.

## [0.88.0] - 2026-07-10

- PDF Templates — configurable document formats: `PdfTemplate` classes (`static $key`, `fields()`, `sampleData()`, `paper()`, `logo()`) with a built-in generic document plus `html()`/Blade `view()` escape hatches.
- Declarative `PdfField` knobs (color, text, select, toggle, number) rendered by `<KinetixPdfTemplate>` — live iframe preview, per-template (and per-team) persisted settings, reset, sample-PDF download; `KinetixPdf` facade (`register`/`render`/`pdf`) applies stored settings.
- Driver auto-detection: `spatie/laravel-pdf` → `barryvdh/laravel-dompdf` → `dompdf/dompdf` (`kinetix.pdf.driver`); endpoints gated by `viewKinetixPdf`; migration tag `kinetix-pdf-migrations`. **(published)**

## [0.87.0] - 2026-07-10

- New `kinetix:upgrade` command re-publishes volatile published assets (components, composables, stores, TS types, translations; recompiles the Vue i18n bundle when available), only refreshing targets the app already published. `kinetix:install` registers `@php artisan kinetix:upgrade` in the host composer.json's `post-autoload-dump` (idempotent) — remove the hook if you keep local edits to published files.

## [0.86.1] - 2026-07-10

- `KinetixTokenManager` list polish: creation date shown, expiry badge next to the name, mobile-friendly row layout. **(published)**

## [0.86.0] - 2026-07-10

- Token expiration — optional expiration date on the developer-token create form (future dates only, validated 422 otherwise), persisted via Sanctum's native `expires_at`, surfaced as `TokenData.expiresAt` with an "Expired" badge in the list. **(published)**

## [0.85.0] - 2026-07-10

- API request logs (opt-in `kinetix.api_logs`): `kinetix.api-log` middleware logs method/path/status/duration/ip/token per request in `terminate()`; bodies opt-in, size-capped, sensitive keys redacted. Feed at `GET {prefix}/api-logs` (gate `viewKinetixApiLogs`), migration tag `kinetix-api-logs-migrations`, retention via `kinetix:api-logs:prune`.
- Webhook delivery logs enriched (`WebhookLogData` carries payload, response, endpoint name/URL); new cross-endpoint feed `GET {prefix}/webhooks/logs` (`webhooks.manage`) with filters.
- `<KinetixIntegrationLogs>` viewer — tabbed feeds (or single via `only`), filters, debounced search, pagination, detail modal with one-click webhook redelivery. **(published)**

## [0.84.0] - 2026-07-10

- `KinetixRoleMatrix` — spreadsheet-style role manager (role cards with member counts, modal editor with feature rows × ability columns, row-toggle by module); same endpoints, `roles.manage` gating and team rules as `KinetixRoleManager`. **(published)**
- `GET {prefix}/permissions/roles` now includes `usersCount` per role.
- `KinetixMemberProvisioner` polish: headline-cased role options (raw slug still submitted), better email placeholder. **(published)**

## [0.83.0] - 2026-07-09

- `{current_team}` is now resolved by route key with a membership check via new `KinetixTeams::currentTeamKey()` — slug/uuid-routed teams work, non-member teams 404; hosts without a teams relation keep the legacy raw-segment behavior. Shared route-prefix URLs use `currentTeam->getRouteKey()`.

## [0.82.0] - 2026-07-09

- Per-module `teams` flags now inherit the global `kinetix.teams` (tri-state: `null` inherits, `true`/`false` overrides), so `KINETIX_TEAMS_ENABLED=true` team-scopes the whole suite; resolution via new `Support\KinetixTeams::enabledFor($module)`. **(published config)** Upgrade: re-publish `kinetix-config` (or set flags to `env('…_TEAMS')` with no default) to adopt inheritance — previously published configs pin each flag to `false`.

## [0.81.0] - 2026-07-09

- Import template download — the import modal offers a "Download template" CSV (header row = importer column labels, auto-mapped on re-upload); per-importer `protected bool $downloadableTemplate = false` and `protected ?string $templateFileName`; new `GET {prefix}/imports/template?importer={token}` endpoint (`kinetix.imports.template`), `KinetixImporter` accepts a `template` prop; i18n `download_template` in en/es/fr/pt. **(published)**

## [0.80.1] - 2026-07-09

- CI fixes: esbuild npm override conflict breaking `npm ci`, testbench
  `query()` helper collision renamed, workflows bumped Node 20 → 22.

## [0.80.0] - 2026-07-09

- `money()` on `TextColumn` and infolist `TextEntry` is now locale-aware via
  intl `NumberFormatter::CURRENCY`; new signature
  `money(string $currency = 'USD', int $divideBy = 1, ?string $locale = null)`
  (`$divideBy` converts minor units, e.g. cents); locale resolves argument →
  `->locale()` → app locale, with a `CODE 1,234.50` fallback without ext-intl.
  Shared `FormatsMoney` concern, mirroring `Summarizer::money()`.

## [0.79.0] - 2026-07-09

- Locale-aware dates: `->date()` / `->dateTime()` with no argument now render
  through Carbon `isoFormat()` in the app locale; new `->isoDate($tokens?)` /
  `->isoDateTime($tokens?)` and per-column/entry `->locale()` override; shared
  `FormatsDates` concern for `TextColumn` and `TextEntry`.
- New `kinetix.formats` config block — app-wide default date/datetime tokens
  (`KINETIX_DATE_FORMAT` / `KINETIX_DATETIME_FORMAT` env keys).
- Date/Month/Week/Range pickers, date filters and `NumberField` now default to
  the application locale (as BCP-47) instead of the browser locale; an explicit
  `->locale()` still wins. **(published — no frontend change required; value
  is serialized from the backend)**
- Behavior change: argument-less `->date()` / `->dateTime()` output is now
  localized instead of fixed English `M j, Y`; call `->date('M j, Y')` to keep
  the exact old output.

## [0.78.0] - 2026-07-09

- Hardening fixes: kanban grouping/moves handle enum `statusColumn` casts;
  the `kanban-move` endpoint now authorizes the record (policy `update` or
  `->authorizeMove()` ability, `->moveScope()` seals tenant constraints into
  the encrypted descriptor); queued imports keep tenant context via the new
  `Importer::context(Request): array` hook (restored as `$this->context` on
  the worker); the `Gate::before` super-admin bypass honors teamless role
  assignments so platform admins keep access inside every team.
- Teams/permissions: new `kinetix-permission-team-migrations` publish tag
  (nullable `team_id` outside the pivot primary key, enabling global +
  team-scoped roles) and a boot-time `Log::warning` when
  `kinetix.permissions.teams` is true but spatie's `permission.teams` is false.
- Table/form API additions: `Column::state()` / `getStateUsing()`,
  `SelectFilter::relationship()` (also `MultiSelectFilter`),
  `Component::columnSpanFull()`, `OnboardingStep::cta()` Closure href,
  `searchable()` / `searchUsing()` on select filters (remote search via
  `KinetixCombobox`), new reusable `KinetixCheckboxList`, new `ViewColumn`
  (host-registered Vue cell components) and `ProgressColumn`, dynamic
  `cell-{column}` scoped slots in `KinetixTable`, and automatic detection of
  `getLabel()` / `getColor()` / `getIcon()` methods on enums for the
  `HasLabel` / `HasColor` / `HasIcon` contracts. **(published)**
- Billing: `is_free` plans column with `Plan::isFree()`; generic trial
  `trial_plan` support — with `trial_generic` enabled, `subscribe()` on a plan
  with `trial_days` starts a database trial (no Stripe subscription or card);
  consumers must add a nullable string `trial_plan` column to the billable
  table; `subscriptionData()` gains `trialPlan`, TS types gain
  `trialPlan` / `trialDays`, and the subscription status component shows the
  trialed plan (new `billing_trial_active_plan` i18n key). **(published)**

## [0.69.3] - 2026-07-02

- `BillingManager::subscribe()` no longer requires an upfront payment method
  for free plans, Stripe trials, or users with a default payment method on
  file. **(published)**

## [0.69.2] - 2026-07-02

- `KinetixPlanCard` shows a trial-days badge under the price; new
  `showPlanTrials` prop on `KinetixPricingTable` to hide the badges.
  **(published)**

## [0.69.1] - 2026-07-02

- New `trial_generic` config setting isolating database-driven generic trials
  from Stripe subscription trials (when active, new Stripe subscriptions
  ignore plan `trial_days` to prevent double-trialling). **(published)**

## [0.69.0] - 2026-07-02

- Plan trials: new `trial_days` column on the `plans` table — new
  subscriptions start with those trial days in Stripe. **(published)**
- `BillingManager::subscriptionData()` now reports trial details (`onTrial`,
  `trialEndsAt`, `onGenericTrial`); `KinetixSubscriptionStatus.vue` shows a
  trial badge and active-trial banner. **(published)**

## [0.68.8] - 2026-07-02

- `BillingManager::resolve()` handles a string/integer `{team}` route
  parameter by querying the corresponding model, avoiding type-mismatch
  crashes. **(published)**

## [0.68.7] - 2026-07-02

- Team billing route prefix corrected to `{team}/billing` (drops the `/teams`
  segment). **(published)**

## [0.68.6] - 2026-07-02

- Team billing support: new `billing.teams` config key
  (`KINETIX_BILLING_TEAMS` env) prefixes billing routes with
  `teams/{team}/billing` and resolves the team model from the request or the
  user's `currentTeam`; billing setup guide updated (Cashier migrations
  publish, `@stripe/stripe-js`, team-scoped setup). **(published)**

## [0.68.5] - 2026-07-01

- `billing.plan_model` config default changed to a string class path to avoid
  class-loading errors in host apps. **(published)**

## [0.68.4] - 2026-07-01

- `KinetixChartWidget` gains null-safety guards on data accessors and an
  empty-state fallback, avoiding runtime TypeError crashes. **(published)**

## [0.68.3] - 2026-07-01

- `KinetixPricingTable` imports `KinetixPlanCard` relatively, fixing
  compilation in host apps publishing under a subdirectory. **(published)**

## [0.68.2] - 2026-07-01

- Fixed `useKinetixStripe` dynamic-import resolution and a flaky calendar
  test; billing page template grid realigned and the "Secure Payments" section
  extracted to a reusable `KinetixSecurePayments.vue`. **(published)**

## [0.68.0] - 2026-06-27

- `AddressPicker::except()` — hide address sub-fields without spelling out the
  full allowlist; composes after `->fields()`. **(published)**

## [0.67.0] - 2026-06-27

- New `ProgressWidget` — goal/quota panel as a horizontal bar or circular
  ring; fluent `value()` / `target()` / `display()` / `caption()` / `color()` /
  `ring()`; registered as `type: 'progress'`. **(published)**

## [0.66.0] - 2026-06-27

- Chart area fills now use a per-series gradient; `Carbon` typehints widened
  to `CarbonInterface`; notification bell trigger uses the shared
  `buttonVariants`.
- New Mail Templates module (`mail_templates`, optional, **(published)**) —
  editable email templates (subject + Markdown/HTML with `{{ variable }}`
  placeholders) stored in `kinetix_mail_templates`, managed via
  `<KinetixMailTemplates>` (list, editor, live preview, send-test); send with
  `KinetixMail::send($to, $key, $data)` (also `render()` / `test()`); gated by
  the `viewKinetixMail` ability; migration tag
  `kinetix-mail-templates-migrations`.
- Fixed vue-i18n compilation crashes from literal `{{ … }}` / `@` in
  translation strings.

## [0.64.0] - 2026-06-27

- New `HeroWidget` (`type: hero`) — greeting + headline value, delta line and
  primary action button, with optional gradient; `ChartWidget::metric()` adds
  headline figures/badges to the chart header (new `KinetixChartMetric` TS
  type). **(published)**
- New `RatingWidget` (`type: rating`) — average score with proportional stars
  and a per-level breakdown of review counts as colored bars;
  `->average()->total()->breakdown([...])`. **(published)**

## [0.62.0] - 2026-06-27

- Period filter end to end **(published)** — `KinetixPeriodFilter.vue`
  (segmented buttons or select), `useKinetixPeriod()` composable (period from
  `?period=`, client-side range, server navigation), and the `Support\Period`
  PHP parser (`range()`, `fromRequest()`, `scope()`); one shared key set
  (`today`/`yesterday`/`7d`/`30d`/`90d`/`month`/`year`/`all` + `custom`)
  keeps client and server in agreement.

## [0.61.0] - 2026-06-27

- Stat cards gain `->badge($text, $color)` trend chips and `->url()` footer
  links; `Widget::headerAction($label, $url, $icon?)` adds header link/button
  actions to Chart, Table and List widgets. **(published)**

## [0.60.0] - 2026-06-27

- More `ChartWidget` variants **(published)**: `chartType('horizontalBar')`,
  stacked areas, stacked bars via `->stacked()`, `->legend()`, and donut
  `->centerLabel($value, $caption)`.

## [0.59.0] - 2026-06-27

- Richer dashboard widgets **(published)**: stat cards gain
  `->icon()->iconColor()` leading icon badges; new `ListWidget` (`type: list`)
  feed panel with `ListItem` icon/subtitle/value/badge/progress/url rows and a
  footer action; chart `area` type; more common dashboard icons in
  `resolveIcon()`.

## [0.58.0] - 2026-06-27

- Failed-job retry/delete in the Queue widget **(published)** —
  `QueueMetrics::failed()` lists recent failed jobs (with or without Horizon),
  `retry($id)` / `forget($id)` backed by new gated endpoints
  `POST {prefix}/queue/retry` and `DELETE {prefix}/queue/failed`;
  `useKinetixQueue()` gains `retry` / `forget`.

## [0.57.0] - 2026-06-27

- New Scheduled Reports module (`reports`, optional) — email an Exporter's
  output on a schedule: register with `KinetixReports::register(
  ScheduledReport::make(...))`, run `kinetix:reports:send` from the scheduler
  (`--frequency=daily|weekly|monthly` or by key); builds CSV/XLSX/PDF via the
  shared `FileWriter` pipeline and mails it as a queueable attachment.
  Backend-only.

## [0.56.0] - 2026-06-27

- New `MediaLibrary` form field **(published)** — multi-file media manager
  (drag-drop upload, thumbnail grid, drag-to-reorder, delete, preview) built
  on `FileUpload`; optional spatie/laravel-medialibrary integration via
  `KinetixMedia::items()` / `KinetixMedia::sync()` (no-op without spatie, so
  the same form code works either way).

## [0.55.0] - 2026-06-27

- `TextInput::copyable()` / `->revealable()` (click-to-copy and masked
  reveal-toggle inputs, new `KinetixCopyableInput.vue`) and
  `Column::copyable()` hover click-to-copy on any table cell. **(published)**

## [0.54.0] - 2026-06-27

- New `TableRepeater` form field **(published)** — a repeater rendered as an
  editable spreadsheet-style table with footer summaries
  (`sum`/`avg`/`count`/`min`/`max`), CSV export, and deferred (default) or
  autosave (`->relationship('items')->autosave()`) persistence via a
  signed-descriptor endpoint; new `kinetix.table-repeater.*` routes and
  `useKinetixTableRepeater()` composable.

## [0.53.0] - 2026-06-27

- New System health widget (`health`, optional, **(published)**) —
  `HealthMetrics` reads spatie/laravel-health stored results (works without
  the package installed); gated `GET {prefix}/health` endpoint (ability
  `viewKinetixHealth`), `<KinetixHealthStatus />` polling component,
  `useKinetixHealth()` composable and `kinetix_health` Inertia share.

## [0.52.0] - 2026-06-27

- New Queue health widget (`queue`, optional, **(published)**) —
  `QueueMetrics` reads Horizon repositories when installed and falls back to
  queue sizes + `failed_jobs` otherwise; gated `GET {prefix}/queue` endpoint
  (ability `viewKinetixQueue`), `<KinetixQueueStats />` polling component,
  `useKinetixQueue()` composable, `kinetix_queue` Inertia share; new config
  keys `queues` and `poll`.

## [0.51.0] - 2026-06-27

- New Presence module (`presence`, optional, **(published)**) — live online
  indicators over a Reverb/Pusher presence channel; Kinetix registers the
  team-aware channel authorization and shares the channel via the
  `kinetix_presence` Inertia prop; `<KinetixOnlineUsers />` avatar facepile
  and `useKinetixPresence()` composable; config keys `channel`,
  `name_attribute`, `avatar_attribute`; requires broadcasting
  (`kinetix:install --broadcasting`).

## [0.50.0] - 2026-06-27

- Resource breadcrumbs **(published)** —
  `Resource::breadcrumbs($operation, $record?)` auto-derives the trail for
  `index`/`create`/`edit`/`show` from navigation label, record title
  (override via `$recordTitleAttribute`) and route base name (override via
  `$routeBaseName`), auto-filling record and `current_team` route params;
  `kinetix:make-resource` now emits breadcrumbs from each controller action
  and a typed `breadcrumbs` page prop.

## [0.49.0] - 2026-06-27

- **Team Switcher module** (`team_switcher`, optional, **(published)**) — header dropdown to switch the active team. Kinetix does not own the `Team` model: it resolves teams by convention (`teams_relation`/`current_relation`/`name_attribute`) and shares them with switch URLs (from `switch_route`) via the `kinetix_teams` Inertia prop; optional `create_route` adds a "New team" entry. `<KinetixTeamSwitcher />` + `useKinetixTeams()`; i18n en/es/fr/pt; degrades gracefully when the route is missing.

## [0.48.0] - 2026-06-27

- **Language Switcher module** (`locale`, optional, **(published)**) — list locales in config, drop `<KinetixLanguageSwitcher />` in the header. Switch is instant via vue-i18n, persisted in session (and on the user's `locale` column with the optional `kinetix-locale-migrations` migration). New `kinetix.locale` middleware applies the persisted locale per request; `POST {prefix}/locale` endpoint is auth-optional so it works on the login screen. `KinetixLocale` static API, `useKinetixLocale()` composable, `kinetix_locale` Inertia prop.

## [0.47.0] - 2026-06-27

- **Wizard `fullWidth` option** (**(published)**) — horizontal step indicator toggle (default `true`); `false` gives a compact, centered indicator. Available on `<KinetixWizard :full-width="false">` and `Wizard::make()->fullWidth(false)`; applies to `stepper`/`default`/`gradient`/`panels` variants.

## [0.46.0] - 2026-06-27

- **Announcements module** (`announcements`, optional, **(published)**) — "what's new" feed with per-user unread badge. `KinetixAnnouncements::publish($title, $body, $level)` (`info`/`feature`/`fix`, optional scheduled `published_at`); header trigger shows unread count + popover, opening marks seen. Backed by `kinetix_announcements` / `kinetix_announcement_views` tables and `useKinetixAnnouncements`; i18n en/es/fr/pt.

## [0.45.0] - 2026-06-27

- **PDF exports** — an `Exporter` may return `'pdf'` from `format()` to produce a landscape-A4 table PDF. Requires the **suggested** `dompdf/dompdf` (^3.0) dependency (only for PDF); CSV/XLSX unchanged; missing dompdf fails fast with an install hint.

## [0.44.0] - 2026-06-27

- **Calendar module** — month-view event scheduler over any Eloquent model via the server-driven `Calendar` builder (`->dateColumn()`, `->endColumn()`, `->title()`, `->color()`, `->url()`, `->query()`, `->heading()`); render `<KinetixEventCalendar :calendar="calendar.toData()" />`.
- 6-week month grid with colored chips, links, "+N more"; client-side month nav; `event-click`/`day-click` events; `week-starts-on` + `locale` props; zero calendar dependency. No migration, route or config. i18n `calendar_*` (en/es/fr/pt).

## [0.43.0] - 2026-06-27

- **Kanban module** — drag-and-drop board over any Eloquent model via the server-driven `Kanban` builder (`->statusColumn()`, `->statuses()` with labels + colors, `->cardTitle()`, `->cardDescription()`, `->query()`, `->heading()`); render `<KinetixKanban :kanban="board.toData()" />`.
- Native HTML5 drag-and-drop persists status moves optimistically (reverts on error) via `POST {prefix}/tables/kanban-move`, guarded by a signed descriptor — only declared status column + statuses are writable. No migration or config flag. i18n `kanban_*` (en/es/fr/pt).

## [0.42.0] - 2026-06-27

- **Saved Views module** (`saved_views`, optional, **(published)**) — per-user table presets. `->saveViews()` on a `Table` adds a Views toolbar dropdown: save search + filters + sort + page size + visible columns under a name, switch presets, star a default. Per-user and team-scoped when `kinetix.teams` is on; backed by `kinetix_saved_views` table + `useKinetixSavedViews`; `TableData.savedViewsKey`. i18n `saved_view*` (en/es/fr/pt).

## [0.41.0] - 2026-06-26

- **Notification Preferences module** (`notification_preferences`, optional, **(published)**) — per-user opt-in matrix of notification types × channels (email / in-app / push). Declare via config or `KinetixNotificationPreferences::types([...])`; gate a notification's `via()` with `channelsFor($user, $type, $channels)` or `allows()`. Defaults to enabled — only opt-outs stored, so new types/channels stay on. `KinetixNotificationPreferences` Vue matrix + composable; `kinetix_notification_preferences` table. i18n `notification_prefs_*` (en/es/fr/pt).

## [0.40.0] - 2026-06-26

- **Tags module** (`tags`, optional, **(published)**) — polymorphic reusable tags stored in their own table (vs the form `TagsInput` string array). `HasKinetixTags` trait + allowlist via `KinetixTags::for([...])`; autocomplete, slug dedupe, automatic team scoping when `kinetix.teams` is on, host `view`/`update` policy honored.
- `TagFilter` table filter (`whereHas`); `KinetixTags` Vue component (chips + autocomplete + create-on-Enter) + `useKinetixTags`; `kinetix_tags` / `kinetix_taggables` tables. i18n `tag_*` (en/es/fr/pt).

## [0.39.0] - 2026-06-26

- Lint & format aligned with the official Laravel Vue starter kit: added `.prettierrc` (single quotes, 4-space, printWidth 80, `prettier-plugin-tailwindcss`), `.prettierignore`, `.editorconfig`; `resources/js` reformatted and now passes `eslint` + `prettier --check`.
- **Comments module** (`comments`, optional, **(published)**) — polymorphic threaded comments. Allowlist models with `KinetixComments::for([...])`; anyone who may view a record can read/post; replies thread one level deep; users edit/delete only their own; deleting a top-level comment removes its replies. `kinetix_comments` table, `useKinetixComments`, `KinetixComments` Vue component. i18n `comment_*` (en/es/fr/pt).

## [0.37.0] - 2026-06-26

- **`KinetixModeToggle`** — drop-in dark-mode header button (Light/Dark/System) via new `useKinetixAppearance`, matching the official starter kit's appearance contract (same `appearance` localStorage key + cookie, `html.dark`, `prefers-color-scheme`).
- **`KinetixAccessibilityMenu`** — compact accessibility quick-menu popover (same controls as `KinetixAccessibilityPanel`) for headers/login/setup.
- `useKinetixAccessibility().set()` now persists server-side best-effort, so the menu works for guests (localStorage mirror still applies).

## [0.36.0] - 2026-06-26

- **`PhoneInput` form field** (`phone-input`, **(published)**) — international phone field with searchable country selector (flag + dial code), storing the full E.164-style string; `->defaultCountry('MX')`, `->countries([...])`. New `Support\DialCodes` map; `KinetixPhoneInput` component.

## [0.35.0] - 2026-06-26

- Two new form fields (**(published)**): **`SlugInput`** (`slug-input`) — live slug generation from a sibling field via `->from('title')` + `->separator()` until manually edited; **`SignaturePad`** (`signature-pad`) — canvas signature storing a PNG data URL, with `->penColor()`, `->backgroundColor()`, `->height()` and Clear. i18n `signature_clear` (en/es/fr/pt).

## [0.34.0] - 2026-06-26

- Three new form fields (**(published)**): **`Slider`** (`slider`) — range slider with `->min()/->max()/->step()`; **`Rating`** (`rating`) — star rating with `->max()` and `->allowHalf()`; **`PinInput`** (`pin-input`) — segmented PIN/OTP input with `->length()`, `->mask()`, `->otp()`, `->numeric()`.

## [0.33.0] - 2026-06-26

- **`NumberField` form field** (`number-field`, **(published)**) — numeric input with stepper buttons, `min`/`max`/`step`, and `Intl.NumberFormat` formatting via `->percent()`, `->currency('USD')`, `->decimals()`, `->numberLocale()`; plus **`NumberInputColumn`** (`number-input`), its inline-editable table twin saving through the cell-update endpoint. `KinetixNumberField` component with a `compact` table mode.

## [0.32.0] - 2026-06-26

- **Wizard `stepper` variant** built on Reka UI Stepper primitives (numbered indicators, titles, descriptions, separators) with a new `orientation` prop (`horizontal`|`vertical`). **Breaking:** `stepper` is now the default wizard variant (was `default`); previous variants remain available.
- **Breaking:** social auth icons are monochrome (`currentColor`) by default in `KinetixSocialButton` / `KinetixConnectedAccounts`; pass `colorized` for brand colors — replaces the previous `branded` prop (which defaulted to on).

## [0.30.0] - 2026-06-26

- **`RichEditor` form field** (`rich-editor`, **(published)**) — rich text field with three swappable drivers via new `config('kinetix.forms.rich_editor')` or per-field `->editor()/->basic()/->tiptap()/->markdown()`: `basic` (default, zero-dependency contenteditable), `tiptap` (optional `@tiptap/core` + `@tiptap/starter-kit`, lazily loaded, inline install notice if missing), `markdown` (zero-dependency textarea + live preview). HTML is not sanitized server-side — escape on output. i18n `editor_*` (en/es/fr/pt).

## [0.29.0] - 2026-06-26

- **`KinetixSocialButton` + bundled brand icons** — reusable single-provider social-auth button (`provider`, `mode="login|link"`, `label`, `branded`, `block`, `variant`, `href`). Local brand icon components under `resources/js/icons/brands/` (github, google, microsoft, gitlab, bitbucket, facebook, x, apple, discord, twitch + fallback) via `brandFor()`; `KinetixConnectedAccounts` reuses the registry. i18n `continue_with`.

## [0.28.0] - 2026-06-26

- **Browser Sessions / device management** (`sessions`, optional) — lists the user's active sessions (device, browser, platform, IP, last-active, "this device" badge) from Laravel's `sessions` table (requires `SESSION_DRIVER=database`); password-gated "log out other sessions" (skipped for passwordless users). Built-in `UserAgentParser` (no external agent dependency); `useKinetixSessions` + `KinetixSessions` component; no migration. i18n `session*` (en/es/fr/pt).

## [0.27.0] - 2026-06-26

- **Connected Accounts / social auth** (`connected_accounts`, optional, requires suggested `laravel/socialite` ^5.0, **(published)**) — sign in/register with a provider (opt-in guest flow, customizable via `KinetixConnectedAccounts::resolveUserUsing()` / `createUserUsing()`); link/unlink providers via the `KinetixConnectedAccounts` Vue manager; set a password for social-only users; lockout protection blocks unlinking the last sign-in method (`prevent_lockout`).
- Backed by the `kinetix_connected_accounts` table (tokens encrypted at rest) and `useKinetixConnectedAccounts`; no User trait needed. New "Connected Accounts" and starter-kit ownership docs. i18n `connected_account_*`, `password_*` (en/es/fr/pt).

## [0.26.0] - 2026-06-26

- **`AddressPicker` form field** (`address-picker`, **(published)**) — structured address storing `{ line1, line2, city, state, postalCode, country }`, with a searchable ISO 3166-1 country select (`Support\Countries`); `->fields([...])` to limit/reorder, `->countries([...])` to override. `KinetixAddressPicker` component.
- **`AddressFilter` table filter** (type `address`) — single text input matching with OR `LIKE` across `->columns([...])`. i18n `address_*` (en/es/fr/pt).

## [0.25.0] - 2026-06-26

- **`KinetixSpotlightTrigger`** — visible header launcher for the Spotlight palette (`⌘K`/`Ctrl K` hint), decoupled via a `window` `kinetix:spotlight` event.
- **`WeekPicker`/`WeekFilter` `->startWeek(0-6)`** — region-aware first day of the week (default Monday); `WeekPicker` now highlights the whole selected week.
- `TimePicker` now renders as an input-style popover trigger and defaults to a 12-hour clock with AM/PM; `->twentyFourHour()` for 24-hour.
- Docs: fixed two Mermaid v11 diagram syntax errors (quoted `subgraph` titles).

## [0.24.1] - 2026-06-26

- Feature flags no longer 500 for guests: a throwing user-scoped resolver resolves as inactive when the scope is `null`, and `all()` resolves per-flag so one bad resolver can't break the set; authenticated-scope errors still surface.

## [0.24.0] - 2026-06-26

- **Accessibility module** (optional, `KINETIX_ACCESSIBILITY_ENABLED`) — per-user preferences: reduce motion, increase contrast, text size, underline links, enhanced focus. Persisted (`kinetix_accessibility` table), shared on every Inertia response, applied to `<html>` before mount (no flash) by the new `KinetixAccessibility` Vue plugin, with a localStorage mirror.
- **`<KinetixAccessibilityPanel />`** (**(published)**), `useKinetixAccessibility`, `GET/POST {prefix}/accessibility` endpoints; screen-reader primitives `<KinetixSkipLink />` and `useKinetixAnnounce()` (shared ARIA live region). i18n `a11y_*`, `skip_to_content` (en/es/fr/pt).

## [0.23.0] - 2026-06-26

- **`DateRangePicker` form field** — start + end date storing `{from,to}`; range calendar popover by default or two native inputs via `->native()`; supports `->numberOfMonths()`, `->weekdayFormat()`, `->fixedWeeks()`, `->locale()`, `->minValue()/->maxValue()`. New `KinetixDateRangePicker` component (**(published)**); pairs with the existing `DateRangeFilter`. i18n `pick_date_range` (en/es/fr/pt).

## [0.22.0] - 2026-06-26

- **Month / Year / Week pickers** as form fields (`MonthPicker` `Y-m`, `YearPicker` `Y`, `WeekPicker` `o-\WW`; popover by default or native input via `->native()`) and table filters (`MonthFilter`, `YearFilter`, `WeekFilter` as an ISO-week date range). New `KinetixMonthPicker`/`KinetixYearPicker`/`KinetixWeekPicker` components (**(published)**).
- Generic `->minValue()` / `->maxValue()` on the `Field` base (mapped to native `min`/`max`), also on `DatePicker`/`DateTimePicker`. i18n `pick_month`/`pick_year`/`pick_week`/`week_of`.
- Time pickers now scroll the selected hour/minute into view on open.

## [0.21.0] - 2026-06-26

- **`TimePicker` form field** (time-only) — scrollable hour/minute columns (+ AM/PM via `->twelveHour()`), stores `H:i`; `->native()` falls back to `<input type="time">`; `->minuteStep()`. New `KinetixTimePicker` component (**(published)**).
- Docs: embedded missing component screenshots (form layouts, TimePicker, DateTimePicker, resource pages); screenshot tool can open a teleported popover before capture (`openSelector`).

## [0.20.1] - 2026-06-26

- **`Action::iconButton()`** — compact icon-only action button (label kept for `aria-label`/tooltip; `ActionData.isIconButton`); `KinetixActionDropdown` with no group label now renders a borderless ghost icon trigger (outline + label are opt-in).

## [0.20.0] - 2026-06-26

- **Reorderable tables**: `Table::reorderable('sort_order')` adds drag-and-drop row reordering with a grip-handle column; order persists to the integer column via a signed, token-guarded `tables/reorder` endpoint; rows default to that order.
- `Table::poll('10s')` now actually works — `KinetixTable` drives it through Inertia's `usePoll` (partial reload, preserves scroll + state); tables aligned to the standard data-table look (`data-state="selected"`, header/hover/selection density).
- Fixed the screenshot light/dark toggle leaking dark captures into light mode.
- Docs: component screenshots embedded per feature (single previews page removed) with a light/dark-aware `<Screenshot>` component.

## [0.19.1] - 2026-06-26

- Impersonation banner "Return to your account" button now uses `text-foreground` explicitly (label was invisible on the banner background); adds dev-only automated component screenshot tooling (Vite gallery + Playwright `npm run screenshots` into `docs/public/screenshots/`).

## [0.19.0] - 2026-06-26

- **GDPR self-service** (optional, `KINETIX_GDPR_ENABLED`): "Download my data" — register sections with `KinetixGdpr::export('profile', fn ($user) => …)`; a queued `GdprExportJob` builds a JSON dump and notifies with a download link. "Delete my account" — `POST {prefix}/gdpr/delete` validates the current password (`kinetix.gdpr.require_password`), then anonymizes configured PII columns or hard-deletes (`kinetix.gdpr.deletion`) and logs out; override with `KinetixGdpr::deleteUsing(...)`.
- **`<KinetixGdprPanel />`** (**(published)**) renders both actions (delete behind a password-gated confirmation); `useKinetixGdpr` for custom UIs. i18n `gdpr_*` (en/es/fr/pt).

## [0.18.0] - 2026-06-26

- Table summaries: `Column::summarize()` renders aggregate footer rows over the full filtered dataset — `Sum`, `Average`, `Count`, `Range`, plus custom `Summarizer::make()->using(...)`; formatting via `label()`/`query()`/`prefix()`/`suffix()`/`numeric()`/`money()`/`hidden()`. New config `kinetix.tables.number_locale`.
- Serialized as `TableData.summaries`/`hasSummaries` and `ColumnData.hasSummary` (**(published)** `KinetixTable` footer).
- Export parity: `ExportColumn::summarize(...)` appends a totals row to CSV/XLSX over the exported query; suppress with `$withSummary = false`.

## [0.17.0] - 2026-06-26

- Wizard multi-step flows: form layout `Wizard::make()->variant(...)->steps([Step::make(...)])` gating advance on the step's required fields, and a standalone **(published)** `<KinetixWizard>` (slot content, `beforeNext` guard, five indicator variants, `useKinetixWizard` composable, `KinetixFormWizard` wrapper).
- Route gating: `kinetix.wizard:<slug>` middleware redirects until completion, persisted per user/team in `kinetix_wizard_completions`; config `wizards`; publish `--tag=kinetix-wizards-migrations`; endpoints `GET/POST {prefix}/wizards/{slug}[/complete]`.

## [0.16.0] - 2026-06-26

- New form layout components alongside `Grid`/`Section`: `Fieldset`, `Tabs`+`Tab` (per-tab schema, optional icon), `Split` (responsive flex row), and `Placeholder` (read-only display, excluded from validation/dehydration).
- All nest arbitrarily, share `columnSpan()`/visibility helpers, and nested fields are auto-discovered for validation/hydration (**(published)** Vue renderer + new `KinetixFormTabs`).

## [0.15.0] - 2026-06-26

- Onboarding module (optional, `KINETIX_ONBOARDING_ENABLED`): backend-driven checklist via `KinetixOnboarding::step(...)->cta(...)->completedUsing(...)`, persisted per user/team in `kinetix_onboarding`; publish `--tag=kinetix-onboarding-migrations`; self-service routes `GET/POST {prefix}/onboarding[/complete|/dismiss]`.
- **(published)** `<KinetixOnboardingChecklist />` + `useKinetixOnboarding`, `<KinetixEmptyState />`, and `<KinetixTour />` + `useKinetixTour` (dependency-free spotlight tour, auto-starts once per id).

## [0.14.0] - 2026-06-26

- Developer Tokens module (optional, requires `laravel/sanctum`, `KINETIX_TOKENS_ENABLED`): self-service dashboard to mint, scope and revoke personal access tokens; `KinetixTokens::scopes([...])` (or `kinetix.tokens.scopes`) declares grantable abilities — with a catalog, tokens need ≥1 declared scope, otherwise default `*`.
- **(published)** `<KinetixTokenManager />` (create with scope picker, one-time plaintext reveal, revoke), `useKinetixTokens`; routes `GET/POST {prefix}/tokens`, `DELETE {prefix}/tokens/{token}`. User model must use Sanctum's `HasApiTokens`.

## [0.13.0] - 2026-06-26

- `Action::shortcut('c')` binds a keyboard hotkey to an action rendered in `KinetixPageHeader` (confirmation modal included); serialized as `ActionData.shortcut`.
- Fixed lang-file `=>` alignment for Pint; docs home regrouped into six feature areas; dev skill: run Pint over the whole repo, not `--dirty`.

## [0.12.0] - 2026-06-26

- Keyboard Shortcuts module (frontend-only): conflict-safe app-wide hotkeys — single keys and sequences fire only when not typing, `mod+` combos always, `?` opens help; native matcher, no extra dependency.
- **(published)** `v-kinetix-hotkey` directive (`@/plugins/kinetixHotkeys`), `useKinetixHotkeys` composable, `<KinetixShortcuts>` cheat-sheet overlay, per-user binding overrides via `setHotkeyOverrides`.

## [0.11.0] - 2026-06-26

- Searchable `Select`: `->searchable()` renders a combobox with a search box — local filtering with `options()`, or remote `->searchUsing($model, $labelColumn, $searchColumns, $valueColumn)` (debounced, lazy) via a signed encrypted token hitting new `POST {prefix}/forms/search`; selected label resolved server-side.
- **(published)** `KinetixCombobox` component; `FormFieldData` gains `isSearchable` + `searchToken`; plain selects unchanged.

## [0.10.1] - 2026-06-26

- Webhooks prefer `spatie/laravel-webhook-server` when installed via new `webhooks.driver` config (`auto`/`spatie`/`native`); SSRF re-checked, deliveries logged to the same `WebhookLog` so the dashboard stays consistent across drivers.

## [0.10.0] - 2026-06-26

- Webhooks module (optional, `KINETIX_WEBHOOKS_ENABLED`): declare events with `KinetixWebhooks::events([...])`, fire with `::fire($event, $payload)` — signed (`X-Kinetix-Signature` HMAC-SHA256), retried, logged deliveries per team scope; SSRF guard rejects private/loopback/metadata IPs unless `allow_private`.
- Dashboard endpoints (gated `webhooks.manage`): CRUD, rotate secret (shown once), test send, logs, redeliver; `kinetix:webhooks:prune` command; publish tag `kinetix-webhooks-migrations`.
- **(published)** `<KinetixWebhookManager>`, `useKinetixWebhooks`, endpoint/log types, `webhook_*` translations; `kinetix-webhooks` Boost skill.

## [0.9.0] - 2026-06-26

- Spotlight Command Palette module (optional, `KINETIX_SPOTLIGHT_ENABLED`): global `Cmd/Ctrl+K` search over models, navigation and actions via `KinetixSpotlight::register([...])` with `SpotlightResource` and `SpotlightLink` sources; authorization-aware (source `->authorize()` + per-record `view` policy filtering).
- Driver `auto` routes through `laravel/scout` when available, else a capped `LIKE` query; endpoint `GET {prefix}/spotlight?q=…`.
- **(published)** `<KinetixSpotlight>` palette owning the shortcut, `useKinetixSpotlight`, item/group types, `spotlight_*` translations; `kinetix-spotlight` Boost skill.

## [0.8.0] - 2026-06-26

- Feature Flags module (optional, `KINETIX_FEATURES_ENABLED`): `KinetixFeatures` facade (`define`/`active`/`inactive`/`all`), resolver receives user or team scope and can defer to Billing plan-gating; driver `auto` uses `laravel/pennant` when installed, else a native closure evaluator.
- `kinetix.feature:<flag>` route middleware (404 when inactive).
- **(published)** `<KinetixFeature flag="…">` gate component with `#denied` slot, `useKinetixFeature`, `kinetix_features` shared prop; `kinetix-feature-flags` Boost skill.

## [0.7.0] - 2026-06-26

- Impersonation module (optional, `KINETIX_IMPERSONATION_ENABLED`): `KinetixImpersonation` facade (`start`/`stop`/`isImpersonating`), original user kept in session, target resolved through the auth provider.
- Safety: `users.impersonate` ability, escalation guard blocking impersonating a super-admin unless you are one (`can_impersonate` override), `kinetix.impersonation.protect` middleware 403s sensitive routes, start/stop audited via the Activity event spine. Endpoints `POST {prefix}/impersonate/{user}` / `DELETE {prefix}/impersonate`.
- **(published)** `<KinetixImpersonationBanner>`, `useKinetixImpersonation`, prebuilt `ImpersonateAction`, state type and translations; `kinetix-impersonation` Boost skill.

## [0.6.1] - 2026-06-26

- Activity Log prefers `spatie/laravel-activitylog` when installed via new `activity.driver` config (`auto`/`spatie`/`native`), normalized to the same `ActivityData` DTO; team scoping carried in `properties.team_id`; **Breaking:** `ActivityLogger::log()`/`KinetixActivity::log()` now return `?Model` and `ActivityLogged` carries a base `Model` (storage-agnostic).

## [0.6.0] - 2026-06-26

- Activity Log module (optional, `KINETIX_ACTIVITY_ENABLED`): `LogsKinetixActivity` trait auto-records created/updated/deleted with old→new diffs, `KinetixActivity` facade logs anything, entries dispatch `ActivityLogged` — the event spine later modules consume.
- Team-scoped `kinetix_activity` store gated by `activity.view`; paginated `GET {prefix}/activity` feed with filters; `kinetix:activity:prune` command; publish tag `kinetix-activity-migrations`.
- **(published)** `<KinetixActivityLog>` self-loading timeline (global or scoped to one record), `useKinetixActivity`, entry/response types, i18n descriptions; `kinetix-activity` Boost skill.

## [0.5.1] - 2026-06-26

- **(published)** `<KinetixSettingsForm page-key="…">` now self-loads its page DTO so app settings can be hosted as a tab in the host's own settings layout; settings endpoints content-negotiate JSON; `useKinetixSettings` gains `load()` + `loading`.

## [0.5.0] - 2026-06-26

- Settings module (optional, `KINETIX_SETTINGS_ENABLED`): class-based `SettingsPage::schema()` panels built on the Forms engine; `KinetixSettings` facade (`get`/`set`/`forget`/`all`/`pages`) — team-scoped, cached, type-preserving, with an `encrypted` option; gated by `settings.manage`; publish tag `kinetix-settings-migrations`; generator `kinetix:make-settings-page`.
- **(published)** `KinetixSettingsForm`, `useKinetixSettings`, `KinetixSettingsPageData` type, `settings_saved` translation; `kinetix-settings` Boost skill; `ROADMAP.md` added.
- **(published)** Fixed last v3 focus rings: `<KinetixForm>` submit button uses `buttonVariants()`; table sort-header uses the v4 `ring-[3px]` ring.

## [0.4.8] - 2026-06-26

- **(published)** Aligned remaining hand-rolled controls (table search box, filter-panel inputs, inline-edit cell, `KinetixTagsInput`) to the shadcn-vue new-york-v4 focus-ring/shadow conventions.

## [0.4.7] - 2026-06-26

- Documentation: doc-vs-code audit documenting previously undocumented capabilities across Tables (`poll()`, `paginated()`, `recordUrl()`, Table-as-a-class), Forms (`extraAttributes()`, conditional `required(Closure)`, `buildSchema()`), Actions, Notifications builder helpers + `kinetix.notifications.broadcast`, Infolists, Resources (`registerPermissions()`, `kinetix:make-relation-manager`), Widgets, and Billing manager methods.

## [0.4.6] - 2026-06-26

- Documentation corrections: removed fabricated `formatStateUsing()` form method (documented `afterStateUpdated()` + `live()` instead), fixed `KINETIX_TEAMS_ENABLED` env name, clarified `vue-i18n:generate` is an optional third-party package, fixed the Membership state diagram and Widgets sparkline example, and documented the read-only View/Show page recipe.

## [0.4.5] - 2026-06-26

- Documentation: completed the Resources docs (route registration incl. soft deletes/team prefix, Resource hooks, Create/Edit views) and added the Roles/Permissions/Membership home card.

## [0.4.4] - 2026-06-24

- **(published)** New `KinetixLabel` (shadcn-vue new-york-v4 Label) adopted across form/role/membership components; fixed membership "Add member" button height misalignment (`h-9` everywhere) and normalized label spacing.

## [0.4.3] - 2026-06-23

- **(published)** Membership components now use the Reka UI / shadcn-vue new-york-v4 primitives (`<KinetixSelect>`, `inputClass`, `statusBadgeClass`) instead of raw HTML; dev-skill rule that every control must trace to the Kinetix primitives and canonical class helpers.

## [0.4.2] - 2026-06-23

- Corrected the `HasTeams` × spatie `HasRoles` `teams()` collision guidance for spatie/laravel-permission v8: `insteadof` alone resolves it, alias optional; docs and skills updated.

## [0.4.1] - 2026-06-23

- Added `kinetix-membership` Boost skill + Membership dev-skill section; rule requiring a consumer-facing Boost skill per feature; documented the `HasTeams` × spatie `HasRoles` `teams()` trait collision fix.

## [0.4.0] - 2026-06-23

- Membership & Provisioning module (optional, `KINETIX_MEMBERSHIP_ENABLED`): admin provisions an email + role, the person activates via a single-use expiring signed link; roles drawn from a curated `assignable_roles` allow-list enforced at provision and activation, so provisioners cannot escalate.
- Registers a `members` feature (`viewAny`/`provision`/`update`/`revoke`) with the permission registry; team-aware gated management endpoints; optional `attach_member`/`detach_member` config callables for host team pivots.
- **(published)** `KinetixMemberList`, `KinetixMemberProvisioner`, `KinetixMemberActivation`, `useKinetixMembers`, `KinetixMemberProvision` type, `member_*`/`activation_*` translations; migration publish tag `kinetix-membership-migrations`; new Membership guide.

## [0.3.2] - 2026-06-23

- Inline table edits now send the `XSRF-TOKEN` cookie (fixing 419s in Inertia apps without a csrf meta tag); all stateful `fetch` calls consolidated into `useKinetixHttp`.

## [0.3.1] - 2026-06-23

- `kinetix:install` now installs the frontend runtime deps the published components import (`reka-ui`, `@internationalized/date`, `@lucide/vue`, `vue-sonner`, `pinia`, `vue-i18n`; optional `--charts` / `--broadcasting`), fixing fresh-install Vite resolve errors; test specs moved out of publishable paths.

## [0.3.0] - 2026-06-23

- Roles & Permissions (optional, `spatie/laravel-permission`): feature-scoped registry (`KinetixPermissions::feature()/resource()`, `{feature}.{ability}` keys, auto-CRUD from Resources), `kinetix:permissions:sync` (`--prune`), `super-admin` Gate bypass, `kinetix.permissions.team` middleware, `kinetix_permissions` Inertia prop, gated role-management endpoints, `KinetixRolesSeeder`; config under `kinetix.permissions`.
- **(published)** `useKinetixCan`, `<KinetixCan>`, `v-can` directive, and drop-in `KinetixRoleManager`/`KinetixRoleForm`/`KinetixPermissionMatrix` (en/es/fr/pt).
- Fixed `kinetix:install` injecting a TypeScript cast into JS entry files.

## [0.2.1] - 2026-06-23

- Fixed `<Teleport>` hydration mismatches with an `isMounted` guard in `KinetixConfirmModal`/`KinetixNotificationDrawer`, and installer-generated `createI18n` now uses `legacy: false`.

## [0.2.0] - 2026-06-23

- New installer command `php artisan kinetix:install` (initializes Pinia + Vue i18n) and setup docs for wiring vue-i18n via Inertia's `withApp`.

## [0.1.1] - 2026-06-22

- CI/CD workflows for PHP/JS testing and VitePress deployment, issue/PR templates, development skill reference, VitePress Mermaid plugin, theme logos/favicon, MIT license.

## [0.1.0] - 2026-06-22

- Initial release: custom UI primitives (Dialog, Popover, Select, ScrollArea) on shadcn v4 tokens; Stripe subscription/billing management; Date/Time/DateTime pickers; Download/Export/Import/Preview prebuilt actions; VitePress docs site; relation managers scoped via `$visibleOn`; Pint integration; **(published)** `KinetixToaster`.
- Fixes: bulk export scopes to selected rows (`Exporter::resolveExportQuery()` auto-`whereKey`), team-mode notification delete/read route binding, notification requests send `Accept: application/json` + credentials, components aligned to shadcn-vue new-york-v4 (`ring-[3px]`), pagination `from`/`to` serialized, `vue-tsc` clean with hand-maintained TS types, table debounce and Echo subscription cleaned up on unmount.
