---
layout: home

hero:
  text: "The UI toolkit for Laravel + Vue + Inertia"
  tagline: "Filament-style fluent PHP APIs, real-time components and full i18n — built for the Laravel starter-kit stack (Vue 3 · Inertia · shadcn-vue)."
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
    details: Declarative CRUD with fluent builders — sortable/searchable/filterable tables, inline editing, schema-driven forms (grid/section/fieldset/tabs/split + multi-step wizards) including searchable comboboxes (local & remote).
    link: /resources
  - icon: 📄
    title: Infolists & Actions
    details: Read-only record views and authorizable, confirmable actions (buttons, bulk, groups) — with optional keyboard shortcuts.
    link: /infolists
  - icon: 🔁
    title: Import/Export & Relations
    details: Queued CSV/XLSX import & export to any disk (bulk scopes to selected rows), plus inline relation managers.
    link: /import-export
  - icon: 🔑
    title: Authorization & Identity
    details: Feature-scoped RBAC (spatie), admin-provisioned membership onboarding, and audited "log in as user" impersonation.
    link: /permissions
  - icon: 🚀
    title: SaaS Platform
    details: Database-backed settings, an activity-log audit trail + event spine, feature flags (Pennant), webhooks, self-service API tokens (Sanctum), GDPR data export & account deletion, and Stripe billing.
    link: /settings
  - icon: ⌨️
    title: Search & Experience
    details: Cmd+K spotlight, conflict-safe keyboard shortcuts, per-user accessibility preferences (reduced motion, contrast, text size) + screen-reader primitives, first-run onboarding (checklist, empty states, product tour), real-time notifications, and dashboard stat/chart widgets.
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
