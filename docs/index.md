---
layout: home

hero:
  text: "The UI toolkit for Laravel + Vue + Inertia"
  tagline: "Filament-style fluent PHP APIs, real-time components and full i18n — built for the Laravel starter-kit stack (Vue 3 · Inertia · shadcn-vue)."
  image:
    src: /logo.png
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
    details: Declarative CRUD with fluent builders — sortable/searchable/filterable tables, inline editing, schema-driven forms.
    link: /resources
  - icon: 📄
    title: Infolists & Actions
    details: Read-only record views and authorizable, confirmable actions (buttons, links, bulk, header/footer, groups).
    link: /infolists
  - icon: 🔁
    title: Import & Export
    details: Queued CSV/XLSX import with header mapping and export to any filesystem disk — bulk exports scope to the selected rows.
    link: /import-export
  - icon: 🔗
    title: Relation Managers
    details: Manage a parent's related records inline, with per-page (edit/view) visibility control.
    link: /relation-managers
  - icon: 🔔
    title: Real-time Notifications
    details: Database + broadcast notifications over Laravel Echo, with token-themed toasts that respect dark mode.
    link: /notifications
  - icon: 💳
    title: Billing & Widgets
    details: Stripe-powered plans, pricing tables and subscription state, plus dashboard stat/chart widgets.
    link: /billing
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

Compile translations for Vue and build:

```bash
php artisan vue-i18n:generate
npm run build
```

That's it — start with [Resources](/resources) to scaffold your first CRUD, or
jump to any feature in the sidebar. For the full installation, theming and
configuration reference, see the [README on GitHub](https://github.com/happones/kinetix#readme).
