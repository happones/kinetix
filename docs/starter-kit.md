# Kinetix & the Laravel starter kit

Kinetix is built for the **official Laravel starter-kit stack** — Inertia + Vue 3
+ shadcn-vue (Reka UI). That's a deliberate choice: Kinetix and the starter kit
share the exact same primitives, so they slot together instead of fighting.

The mental model is simple:

> **The starter kit owns authentication and the basic account.**
> **Kinetix owns the app-building toolkit — and the deltas the starter kit doesn't ship.**

Kinetix is a **complement, not a replacement**. You almost never need to rip out
a starter-kit feature to adopt Kinetix; you add Kinetix alongside it and only
reach for a Kinetix module where the starter kit leaves a gap.

## Who owns what

| Area | Laravel starter kit | Kinetix | Recommendation |
| ---- | :-----------------: | :-----: | -------------- |
| Login / register / password reset | ✅ | — | **Starter kit.** Kinetix doesn't touch auth. |
| Email verification, password confirmation | ✅ | — | **Starter kit.** |
| Two-factor authentication (2FA) | ✅ (Fortify) | — | **Starter kit.** Kinetix intentionally ships **no** 2FA — it would duplicate Fortify with the same primitives. |
| Profile / password settings | ✅ | — | **Starter kit.** |
| Appearance (light/dark/system) | ✅ | — | **Starter kit.** Kinetix components honor the `html.dark` class it sets. |
| Delete account | ✅ (basic) | ✅ (GDPR: + export, anonymize, queue, audit) | **Either** — see [GDPR overlap](#gdpr-account-deletion-overlap). |
| Data export ("download my data") | — | ✅ (GDPR) | **Kinetix.** This is the gap the starter kit leaves. |
| Social login / OAuth (sign in with provider) | — | ✅ ([Connected Accounts](/connected-accounts)) | **Kinetix.** The starter kit ships no OAuth at all — this is a complete feature, not a complement. |
| Link / unlink connected accounts | — | ✅ ([Connected Accounts](/connected-accounts)) | **Kinetix.** |
| Set password for social-only users | — | ✅ ([Connected Accounts](/connected-accounts)) | **Kinetix.** |
| Active sessions / device management | — | ✅ ([Browser Sessions](/sessions)) | **Kinetix.** |
| Resources, Tables, Forms, Infolists | — | ✅ | **Kinetix.** The core toolkit. |
| Notifications, Widgets, Spotlight, Activity | — | ✅ | **Kinetix.** |
| Roles & permissions, Membership, Impersonation | — | ✅ | **Kinetix.** |
| Billing, Webhooks, API tokens, Feature flags | — | ✅ | **Kinetix.** |
| Onboarding, Accessibility, Settings hub | — | ✅ | **Kinetix.** |

`✅` ships it · `—` not included · `🛣️` planned (see the roadmap).

## How they sit together

A typical starter-kit app keeps its generated `settings/*` pages and simply
**mounts Kinetix panels next to them**. For example, the starter kit's
`settings/Profile.vue` keeps profile + password + 2FA + delete, and you add a
sibling page (or a tab) that mounts Kinetix's [Settings](/settings),
[API tokens](/tokens), or [GDPR export](/gdpr) panels. Kinetix's
[Settings](/settings) module is itself a host for arbitrary panels, so it's a
natural place to compose both worlds.

Because both use shadcn-vue tokens, the Kinetix panels inherit your theme with no
extra styling.

## Wide tables & the `min-w-0` layout fix

The starter kit's content area is a **flex** column, and a flex item defaults to
`min-width: auto` — it refuses to shrink below its content. A wide
[Kinetix table](/tables) (many columns) therefore grows that column and pushes
the whole page, overflowing the viewport, instead of scrolling inside its own
card.

Kinetix already does its part — the table card carries `min-w-0 max-w-full` and
the scaffolded pages wrap in `… min-w-0 …`. The missing link is on the starter
kit's **content flex items**, which ship without `min-w-0`. Add it once and every
page (not just Kinetix) scrolls correctly:

**1. `resources/js/components/ui/sidebar/SidebarInset.vue`** (the `sidebar` layout's `<main>`):

```diff
  :class="cn(
-     'bg-background relative flex w-full flex-1 flex-col',
+     'bg-background relative flex w-full min-w-0 flex-1 flex-col',
      'md:peer-data-[variant=inset]:m-2 …',
      props.class,
  )"
```

**2. `resources/js/components/AppContent.vue`** (the `header` layout's `<main>`):

```diff
- <main class="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-4 rounded-xl" …>
+ <main class="mx-auto flex h-full w-full min-w-0 max-w-7xl flex-1 flex-col gap-4 rounded-xl" …>
```

That's it — one token per content wrapper. (The `overflow-x-hidden` the sidebar
layout already passes only *clips* at the page level; `min-w-0` is what lets the
column shrink so the table's own `overflow-x-auto` scrollbar does the work.)

## GDPR / account-deletion overlap

This is the one genuine overlap. The starter kit ships a simple **Delete
account** action; Kinetix's [GDPR module](/gdpr) ships a **superset** —
data **export** *and* account **deletion / anonymization** (queued, with a
notification and audit trail). Pick one of two clean approaches so you never
render two "Delete account" buttons:

### Option A — split responsibilities (recommended)

Keep the starter kit's **Delete account** as-is, and use Kinetix for the
**export** it doesn't have. Mount only the export side:

```vue
<script setup lang="ts">
import { useKinetixGdpr } from "@/composables/useKinetixGdpr";

const { exportData } = useKinetixGdpr();
</script>

<template>
  <!-- Add a "Download your data" action next to the starter kit's settings -->
  <button @click="exportData()">Download my data</button>
</template>
```

You get the missing export feature with zero duplication of the delete flow.

### Option B — upgrade to the Kinetix flow

Replace the starter kit's delete section with the full Kinetix panel (export +
anonymization + queue + audit). Remove the starter kit's **Delete account**
block from `settings/Profile.vue`, then mount:

```vue
<script setup lang="ts">
import KinetixGdprPanel from "@/components/kinetix/KinetixGdprPanel.vue";
</script>

<template>
  <KinetixGdprPanel :require-password="true" />
</template>
```

> **Avoid double delete buttons.** Whichever option you pick, make sure only
> **one** "Delete account" control is mounted — either the starter kit's or
> Kinetix's, never both.

## Rule of thumb

- **Auth, basic account settings, 2FA, appearance → leave to the starter kit.**
- **Everything that builds your actual application → reach for Kinetix.**
- **For the deletion/export overlap → choose Option A or B above.**
