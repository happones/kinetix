---
layout: home

hero:
  text: "The hybrid framework for Laravel + Vue + Inertia"
  tagline: "One package spanning backend and frontend — fluent PHP builders that render polished, real-time shadcn/Vue components, with full i18n."
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
  - icon: 🤖
    title: AI-ready
    details: Built to be developed with coding agents — an agent skill per module (46+, auto-published by kinetix:install into .claude/skills or wherever your agent looks), a dedicated Laravel Boost best-practices skill, and per-feature docs + tests that keep Claude Code, Codex & friends on rails.
    link: /installation
---

::: warning Pre-1.0 — under active development
Kinetix does not yet have a release candidate for v1: APIs, published
components and defaults may still change between minor versions. Pin your
version and review the
[changelog](https://github.com/happones/kinetix/blob/main/CHANGELOG.md) before
upgrading. A **beta** and a **release candidate** on the road to **v1.0** will
be announced shortly — [watch the repo](https://github.com/happones/kinetix)
to stay posted.
:::

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

## Built with Kinetix

Real products shipped on Kinetix — attractive tools for devs, built end to end
with this package:

<div class="kx-showcase">
  <a class="kx-showcase-card" href="https://webhookcatcher.com" target="_blank" rel="noopener">
    <img src="/showcase/webhookcatcher.png" width="56" height="56" alt="WebhookCatcher" />
    <div>
      <strong>WebhookCatcher</strong>
      <p>Debug and monitor your webhooks in real time — free and registration-free. Capture and inspect every request to optimize your integrations.</p>
    </div>
  </a>
  <a class="kx-showcase-card" href="https://pokkeri.com" target="_blank" rel="noopener">
    <img src="/showcase/pokkeri.png" width="56" height="56" alt="Pokkeri" />
    <div>
      <strong>Pokkeri</strong>
      <p>A complete Scrum ecosystem for high-performing teams — Planning Poker, realtime Kanban, backlog, reports and retrospectives in one platform.</p>
    </div>
  </a>
</div>

## Support Kinetix

Kinetix is free and open source. If it saves you time, consider fueling its
development:

<a class="kx-bmc" href="https://buymeacoffee.com/happones" target="_blank" rel="noopener">
  <img src="https://cdn.buymeacoffee.com/buttons/v2/default-yellow.png" alt="Buy Me A Coffee" height="44" />
</a>

<style>
.kx-showcase {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 16px;
    margin-top: 16px;
}
.kx-showcase-card {
    display: flex;
    gap: 14px;
    align-items: flex-start;
    padding: 18px;
    border: 1px solid var(--vp-c-divider);
    border-radius: 12px;
    background: var(--vp-c-bg-soft);
    text-decoration: none !important;
    color: inherit;
    transition: border-color 0.2s, background 0.2s;
}
.kx-showcase-card:hover {
    border-color: var(--vp-c-brand-1);
}
.kx-showcase-card img {
    border-radius: 10px;
    flex-shrink: 0;
}
.kx-showcase-card strong {
    color: var(--vp-c-text-1);
}
.kx-showcase-card p {
    margin: 4px 0 0;
    font-size: 13px;
    line-height: 1.5;
    color: var(--vp-c-text-2);
}
.kx-bmc {
    display: inline-block;
    margin-top: 12px;
}
.kx-bmc img {
    border-radius: 8px;
}
</style>
