# Accessibility

Inclusive platforms let users adapt the interface to their needs. Kinetix ships
**per-user accessibility preferences** — persisted server-side and applied to the
document with no flash — plus the screen-reader primitives a real platform needs
(skip link, live-region announcer).

## Preferences

| Preference | Effect |
|---|---|
| **Reduce motion** | Near-instant animations/transitions (`prefers-reduced-motion` for everyone, forced for this user). |
| **Increase contrast** | Outlines interactive elements, underlines links, strong focus. |
| **Text size** | `normal` / `large` / `x-large` — scales the rem-based UI. |
| **Underline links** | Always underline links, not just on hover. |
| **Enhanced focus** | A thicker, always-visible focus outline (keyboard users). |

These are the most impactful, commonly-requested toggles; each maps to a class on
`<html>` (`kx-reduce-motion`, `kx-high-contrast`, `kx-text-large` / `kx-text-x-large`,
`kx-underline-links`, `kx-enhanced-focus`).

---

## Installation

```bash
php artisan vendor:publish --tag=kinetix-accessibility-migrations
php artisan migrate
```

```php
'accessibility' => [
    'enabled'  => env('KINETIX_ACCESSIBILITY_ENABLED', true),
    'defaults' => [
        'reducedMotion'  => false,
        'highContrast'   => false,
        'textSize'       => 'normal',
        'underlineLinks' => false,
        'enhancedFocus'  => false,
    ],
],
```

Install the plugin once in your Inertia app entry — it injects the accessibility
CSS and applies the saved preferences **before the app mounts** (no flash):

```ts
import { KinetixAccessibility } from "@/plugins/kinetixAccessibility";

createApp({ render: () => h(App, props) })
  .use(KinetixAccessibility)
  .mount(el);
```

The preferences are shared on every Inertia response (`kinetix_accessibility`)
and mirrored to `localStorage` for instant re-application.

---

## The preferences panel

```vue
<script setup lang="ts">
import KinetixAccessibilityPanel from "@/components/KinetixAccessibilityPanel.vue";
</script>

<template>
  <KinetixAccessibilityPanel />
</template>
```

Each change applies to the document immediately and is saved to the user's
profile (`POST {prefix}/accessibility`). `useKinetixAccessibility()` exposes the
reactive `prefs` and `set(key, value)` for a custom UI. All strings are localized
(`a11y_*`, en/es/fr/pt).

<Screenshot name="accessibility-panel" alt="Accessibility preferences panel" />

---

## Screen-reader primitives

### Skip link

Let keyboard / screen-reader users jump past the nav straight to the content.
Place it first in your layout; give your main region a matching id:

```vue
<KinetixSkipLink target="#main" />
<!-- … nav … -->
<main id="main">…</main>
```

It's visually hidden until focused, then the first item in the tab order.

### Announcing async updates

Screen readers don't notice silent DOM changes (a toast, "12 results", a removed
row). Announce them through a shared ARIA live region:

```ts
import { useKinetixAnnounce } from "@/composables/useKinetixAnnounce";

const { announce } = useKinetixAnnounce();
announce("Saved");                 // polite
announce("Upload failed", true);   // assertive (interrupts)
```

---

## Endpoints

| Method | Route                      | Name                          |
| ------ | -------------------------- | ----------------------------- |
| `GET`  | `{prefix}/accessibility`   | `kinetix.accessibility.index` |
| `POST` | `{prefix}/accessibility`   | `kinetix.accessibility.update`|

Each user reads and updates only their own preferences.
