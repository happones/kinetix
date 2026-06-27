---
name: kinetix-accessibility
description: "Per-user accessibility preferences (reduced motion, high contrast, text size, underline links, enhanced focus) persisted server-side + applied flash-free, plus screen-reader primitives (skip link, live-region announcer). Activates when wiring a11y settings, the panel, or SR support."
license: MIT
metadata:
  author: happones
---

# Kinetix Accessibility Development

## When to Apply

Activate this skill when:
- Mounting `<KinetixAccessibilityPanel>` or wiring per-user a11y preferences.
- Adding the compact `<KinetixAccessibilityMenu>` (popover) to the header, login
  page or setup wizard (guest-safe — server persist is best-effort).
- Adding the `<KinetixModeToggle>` dark-mode button (Light/Dark/System), backed by
  `useKinetixAppearance` which shares the starter kit's `appearance` storage.
- Installing the `KinetixAccessibility` Vue plugin.
- Adding a `<KinetixSkipLink>` or announcing async updates to screen readers.

## Documentation

For full details, reference `docs/accessibility.md` (published at https://happones.github.io/kinetix/accessibility).

## Configuration

```bash
php artisan vendor:publish --tag=kinetix-accessibility-migrations && php artisan migrate
```

```php
'accessibility' => [
    'enabled'  => env('KINETIX_ACCESSIBILITY_ENABLED', false),
    'defaults' => ['reducedMotion' => false, 'highContrast' => false, 'textSize' => 'normal', 'underlineLinks' => false, 'enhancedFocus' => false],
],
```

## How it works

- **Per-user, self-service**: `GET/POST {prefix}/accessibility` (`AccessibilityController`),
  one `kinetix_accessibility` row per user (`AccessibilityManager::for/update`,
  merged over config defaults; `AccessibilityData` DTO normalizes values + textSize enum).
- **Shared + applied flash-free**: the prefs ship on every Inertia response as
  `kinetix_accessibility`. Install the **`KinetixAccessibility` plugin**
  (`app.use(...)`) — it injects the a11y CSS and applies classes to `<html>`
  before mount, reading the initial `data-page` prop + a localStorage mirror.
- **Classes on `<html>`**: `kx-reduce-motion`, `kx-high-contrast`,
  `kx-text-large`/`kx-text-x-large`, `kx-underline-links`, `kx-enhanced-focus`
  (CSS is injected by the plugin — theme-agnostic, `currentColor` outlines).

## Frontend

```vue
<KinetixAccessibilityPanel />   <!-- text-size segmented control + 4 toggles -->
```

- `useKinetixAccessibility()` → `{ prefs, set(key, value) }` (optimistic apply +
  persist + localStorage). `applyKinetixAccessibility(prefs)` is the standalone
  class-applier (used by the plugin).
- `<KinetixSkipLink target="#main" />` — visually hidden until focused.
- `useKinetixAnnounce()` → `announce(message, assertive?)` via a shared ARIA
  live region for SR-only announcements (toasts, counts, removed rows).

i18n `a11y_*` / `skip_to_content` (en/es/fr/pt). Tests: `AccessibilityTest`,
`useKinetixAccessibility.spec.ts`.
