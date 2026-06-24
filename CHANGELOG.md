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
