# Kinetix Roadmap & Module Map

Kinetix accelerates MVP and SaaS development by shipping the **repetitive,
every-product features** as first-class, opt-in modules for the **Vue 3 +
Inertia.js + Laravel** stack.

**Positioning:** Kinetix is a *complement, not a replacement* for the official
Laravel starter kit. The starter kit owns auth, basic account, **2FA**,
appearance and basic account deletion; Kinetix owns the application layer and
the deltas on top. See [`docs/starter-kit.md`](docs/starter-kit.md) for the full
overlap matrix.

> This document is the current-state map of what's shipped plus what's on the
> table next. Per-release detail lives in [`CHANGELOG.md`](CHANGELOG.md).
> Current version: **v0.202.0** — 50+ modules.

---

## Architecture strategy: optional & configurable

Kinetix stays lightweight by treating third-party packages as **suggested,
optional dependencies**, detected at runtime:

1. **Dynamic detection** — a feature that can use an optional package detects the
   precise symbol it relies on, never assumes it (Scout → `Searchable`,
   Activity → `ActivitylogServiceProvider`, Webhooks → `WebhookCall`, Feature
   flags → `Pennant\Feature`, Tokens → `Sanctum`, Horizon, spatie-health,
   spatie-medialibrary, Cashier, …). Optional packages are declared under
   `composer.json` → `suggest`; optional frontend peers (e.g.
   `@tanstack/vue-table`, Tiptap) under `peerDependenciesMeta`.
2. **Master switch per feature** in `config/kinetix.php`, off by default.
3. **A native fallback that fully works** — when the optional package is absent
   the feature degrades to a built-in driver (never silently "skips"), so the
   Vue components always have data to render.
4. **SemVer** — each module is a backward-compatible minor release.

Every module ships with a `docs/<feature>.md` page, a `kinetix-<feature>` Boost
skill, translations (en/es/fr/pt) and tests.

---

## Shipped

### Resources & data display
- **Resources** — CRUD scaffolding (`Resource` + index/create/edit/view), with
  auto-discovery and a dedicated provider scaffold. `docs/resources.md`
- **Tables** — server-driven data grids: columns, filters, search, sort
  (incl. **relationship-column sorting**), pagination, inline-editable columns,
  bulk/record actions, reorder, footer summaries, optional **KPI stat cards**
  (batched into one query), and an opt-in **client-side (TanStack) mode**.
  `docs/tables.md`
- **Infolists** — read-only record views. `docs/infolists.md`
- **Relation Managers** — related-record tables on a parent page.
  `docs/relation-managers.md`
- **Saved Views** — per-user presets of search/filters/sort/columns.
  `docs/saved-views.md`
- **Widgets** — stat/chart/progress/table widget grid (masonry, period filter).
  `docs/widgets.md`
- **Kanban** — draggable board view. `docs/kanban.md`
- **Calendar** — event calendar (month/week/day, timezone-correct).
  `docs/calendar.md`

### Forms & input
- **Forms** — schema-driven form builder: 30+ field types, layouts
  (Grid/Section/Fieldset/Tabs/Split/Placeholder), lifecycle hooks, and
  validation via **fluent rules, FormRequest bridge, or live Precognition**,
  with tab/wizard error focus. `docs/forms.md`
- **Wizard** — multi-step form layout + standalone `<KinetixWizard>` with
  completion-gating middleware. `docs/wizard.md`
- **Table Repeater** — editable table-style form field (deferred + autosave).
  `docs/table-repeater.md`
- **Business Hours** — a weekly per-day schedule field (enable/disable + time
  ranges + "apply to all days"), a `WeeklySchedule` value object with
  `isOpenAt()` / `effectiveSchedule()`, and the `AsWeeklySchedule` cast.
  `docs/forms.md`

### Actions & navigation
- **Actions** — fluent action builder (confirm modals, authorization, events).
  `docs/actions.md`
- **Spotlight** — `Cmd+K` command palette (Scout-aware, authorization-gated).
  `docs/spotlight.md`
- **Keyboard Shortcuts** — app-wide hotkeys composing with Actions/Spotlight.
  `docs/keyboard-shortcuts.md`
- **Breadcrumbs** — auto breadcrumbs from Resources. `docs/breadcrumbs.md`
- **Period Filter** — shared date-range/period filter. `docs/period-filter.md`
- **Onboarding** — first-run checklist, empty states, product tour.
  `docs/onboarding.md`

### Notifications & collaboration
- **Notifications** — local / database / broadcast delivery.
  `docs/notifications.md`
- **Notification Preferences** — per-channel, per-type opt-in matrix.
  `docs/notification-preferences.md`
- **Comments** — threaded record comments. `docs/comments.md`
- **Tags** — taggable models + tag filter. `docs/tags.md`
- **Announcements** — app-wide announcement banners. `docs/announcements.md`
- **Presence** — online/typing presence via Reverb. `docs/presence.md`
- **Mail Templates** — DB-editable mailables. `docs/mail-templates.md`

### Import / export / documents
- **Import & Export** — CSV/XLSX importers + exporters. `docs/import-export.md`
- **Reports** — scheduled, emailed reports. `docs/reports.md`
- **Reports Center** — queued, DB-tracked report generation for large datasets.
  `docs/reports-center.md`
- **PDF Templates** — PDF generation (dompdf). `docs/pdf-templates.md`
- **Media Library** — media field + optional spatie-medialibrary bridge.
  `docs/media-library.md`

### SaaS platform
- **Billing** — Cashier + Stripe subscriptions/plans. `docs/billing.md`
- **Plan Gating** — capability + quota gating tied to a plan: `planAllows()` /
  `planLimit()` / `isWithinPlanLimit()` on `HasPlan`, the `kinetix.plan`
  (`EnsurePlanCapability`) middleware, the `EnforcesPlanLimits` trait, and the
  `<KinetixPlanGate>` / `<KinetixPlanLock>` upsell components. `docs/billing.md`
- **Metered Usage & Credits** — a consumption model (`HasMeteredUsage` →
  `consume()` / `meteredUsage()` / top-up `Credit`s) that feeds the usage-meter
  and progress widgets. `docs/billing.md`
- **Membership** — team membership + invitations. `docs/membership.md`
- **Team Switcher** — active-team switching. `docs/team-switcher.md`
- **Permissions** — spatie-permission bridge + `<KinetixCan>`.
  `docs/permissions.md`
- **Credentials** — password lifecycle (expiry, history, forced change,
  temporary credentials) + what it takes to sign in with a username or phone.
  `docs/credentials.md`
- **Feature Flags** — Pennant bridge + `<KinetixFeature>`. `docs/feature-flags.md`
- **Entitlements** — composes flags × plan capabilities × usage limits × role
  permissions under one declared name, with a verdict that says which layer
  refused. `docs/entitlements.md`
- **Impersonation** — audited "log in as user". `docs/impersonation.md`
- **Activity Log** — audit trail + event spine. `docs/activity.md`
- **Webhooks** — customer-facing webhook delivery (SSRF-safe). `docs/webhooks.md`
- **Developer Tokens** — Sanctum personal access tokens. `docs/tokens.md`
- **Connected Accounts** — social/OAuth account linking.
  `docs/connected-accounts.md`
- **Integration Logs** — outbound API request logging. `docs/integration-logs.md`
- **Health** — spatie-health status widget. `docs/health.md`
- **Queue** — Horizon-aware queue + failed-job widget. `docs/queue.md`
- **Sessions** — device/session management. `docs/sessions.md`

### Security & compliance
- **GDPR** — "download my data" + gated account deletion. `docs/gdpr.md`
- **Confidential Fields** — field-level encryption + reveal gate.
  `docs/confidential.md`
- **Accessibility** — a11y helpers/utilities. `docs/accessibility.md`
- **Cookie Consent** — configurable consent banner. `docs/cookie-consent.md`

### Configuration & localization
- **Settings** — schema-driven settings pages (team/user/global scopes).
  `docs/settings.md`
- **Locale** — language switcher + i18n plumbing. `docs/locale.md`
- **Timezone Picker** — per-user timezone selection. `docs/timezone-picker.md`

---

## Planned / candidate features

The three candidates previously parked here — **plan-gating**, the
**weekly business-hours field**, and **metered usage + credits** — have all
shipped and now live under [Shipped](#shipped). Form reactivity landed too:
conditional fields (`visibleWhen` / `requiredWhen` / …) and the server-driven
`$get` / `$set` loop (dependent options, `afterStateUpdated`).

No new candidates are committed yet — the next ones will be recorded here after
a dedicated brainstorm. Per-release detail lives in
[`CHANGELOG.md`](CHANGELOG.md).

---

## Explicitly not building

- **A 2FA module** — the official starter kit + Fortify already ship it. A
  possible future *"require 2FA"* policy/middleware helper that **consumes**
  Fortify state is valid if requested, but a full 2FA module is out of scope.

---

> Per-release detail and dates live in [`CHANGELOG.md`](CHANGELOG.md).
