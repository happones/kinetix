---
layout: home

hero:
  text: "The hybrid framework for Laravel + Vue + Inertia"
  tagline: "One package spanning backend and frontend — Filament-style fluent PHP APIs that render polished, real-time shadcn/Vue components, with full i18n."
  image:
    light: /logo.png
    dark: /logo_w.png
    alt: Kinetix
  actions:
    - theme: brand
      text: Get Started
      link: /installation
    - theme: alt
      text: View on GitHub
      link: https://github.com/happones/kinetix

features:
  - icon: 🧱
    title: Resources, Tables & Forms
    details: Declarative CRUD with fluent builders — sortable/searchable/filterable tables with saved views, inline editing, schema-driven forms (grid/section/fieldset/tabs/split + multi-step wizards) with rich fields (combobox, number, slider, rating, signature, phone…), and auto-derived breadcrumbs.
    link: /resources
  - icon: 🗂️
    title: Boards, Calendar & Records
    details: Drag-and-drop Kanban boards, a timezone-correct event calendar with month/week/day views and a built-in event modal/sheet, read-only infolists, authorizable/confirmable actions (buttons, bulk, groups), inline relation managers, threaded comments and polymorphic tags.
    link: /kanban
  - icon: 🔁
    title: Import/Export
    details: Queued CSV/XLSX/PDF import & export to any disk (bulk scopes to selected rows), with a fluent column/mapping API.
    link: /import-export
  - icon: 🔑
    title: Authorization & Teams
    details: Feature-scoped RBAC (spatie), admin-provisioned membership onboarding, audited "log in as user" impersonation, and a convention-based multi-team switcher.
    link: /permissions
  - icon: 🔐
    title: Account & Security
    details: Social login & connected accounts (GitHub, Google, Microsoft & more) with set-password for social-only users, browser-session/device management, self-service API tokens (Sanctum), and GDPR data export & account deletion.
    link: /connected-accounts
  - icon: 🚀
    title: SaaS Platform & Ops
    details: Database-backed settings, an activity-log audit trail + event spine, feature flags (Pennant), webhooks, Stripe billing, plus embeddable Horizon queue-health and spatie/laravel-health status widgets.
    link: /settings
  - icon: ⌨️
    title: Search & Experience
    details: Cmd+K spotlight, conflict-safe keyboard shortcuts, accessibility preferences + screen-reader primitives, onboarding (checklist, empty states, product tour), real-time notifications & presence/online indicators, a "what's new" feed, dark-mode & language switchers, a searchable timezone picker, and dashboard stat/chart widgets.
    link: /spotlight
---

## Quick Start

Install the package:

```bash
composer require happones/kinetix
```

Publish the assets (components, stores, types, translations, config):

```bash
php artisan vendor:publish --tag=kinetix-config
php artisan vendor:publish --tag=kinetix-components
php artisan vendor:publish --tag=kinetix-translations
```

Compile translations for Vue and build (`vue-i18n:generate` comes from the
optional [`happones/laravel-vue-i18n-generator`](https://github.com/happones/laravel-vue-i18n-generator)
package — install it first, or use your own vue-i18n toolchain):

```bash
composer require happones/laravel-vue-i18n-generator
php artisan vue-i18n:generate
npm run build
```

That's it — start with [Resources](/resources) to scaffold your first CRUD, or
jump to any feature in the sidebar. For the full installation, theming and
configuration reference, see the [README on GitHub](https://github.com/happones/kinetix#readme).
