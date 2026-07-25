# Onboarding

Kinetix Onboarding helps new users reach value fast with three composable
pieces:

- a **first-run checklist** (backend-driven, per-user/team progress),
- a reusable **empty-state** block for "no data yet" screens, and
- a lightweight, dependency-free **product tour**.

---

## Installation

The checklist persists progress, so publish and run its migration:

```bash
php artisan vendor:publish --tag=kinetix-onboarding-migrations
php artisan migrate
```

```php
'onboarding' => [
    'enabled' => env('KINETIX_ONBOARDING_ENABLED', true),
    // Track progress per team instead of per user.
    'teams'   => env('KINETIX_ONBOARDING_TEAMS', false),
],
```

The empty-state and tour components are frontend-only and need no migration or
config.

---

## 1. The checklist

### Declaring steps

Declare steps in a service provider's `boot()`. A step can **auto-complete** from
app state via `completedUsing`, or be a **manual** step the user ticks off:

```php
use Happones\Kinetix\Onboarding\KinetixOnboarding;

KinetixOnboarding::step('verify-email', 'Verify your email')
    ->description('Confirm your address to unlock everything.')
    ->cta('Resend email', '/email/verify')
    ->icon('mail')
    ->completedUsing(fn ($user) => $user->hasVerifiedEmail());

KinetixOnboarding::step('invite-team', 'Invite a teammate')
    ->description('Collaboration is better together.')
    ->cta('Invite', '/team/members')
    ->icon('user');

KinetixOnboarding::step('read-docs', 'Read the quickstart'); // manual, no CTA
```

- A step with `completedUsing` is **auto-detected** — its completion is computed
  live on every read and is never stored.
- A step **without** `completedUsing` is **manual** — the checklist shows a
  "Mark as done" button, and the completion is persisted.

### Request-dependent CTA URLs (teams, tenants)

Steps register in `boot()` — before any request exists — so a URL that depends
on request state (the current team, a tenant prefix) can't be built as a plain
string there. Pass a **Closure** instead; it receives the authenticated user
and is resolved on every read:

```php
KinetixOnboarding::step('invite-team', 'Invite a teammate')
    ->cta('Invite', fn ($user) => route('teams.members', $user->currentTeam));
```

### Mounting the checklist

```vue
<script setup lang="ts">
import KinetixOnboardingChecklist from "@/components/kinetix/KinetixOnboardingChecklist.vue";
</script>

<template>
  <KinetixOnboardingChecklist />
</template>
```

<Screenshot name="onboarding-checklist" alt="Onboarding checklist" />

It renders a progress bar, each step with its icon/description/CTA, a "Mark as
done" button for manual steps, and a **Dismiss** control. It hides itself once
dismissed and (by default) once every step is complete — pass
`:hide-when-complete="false"` to keep it visible.

`useKinetixOnboarding()` exposes `state`, `load`, `complete(step)`, `dismiss()`
for a custom UI.

---

## 2. Empty states

Drop `KinetixEmptyState` wherever a list or section has no data yet:

```vue
<KinetixEmptyState
  icon="user"
  :title="$t('No members yet')"
  :description="$t('Invite a teammate to get started.')"
>
  <button :class="buttonVariants({ size: 'sm' })">Invite</button>
</KinetixEmptyState>
```

<Screenshot name="empty-state" alt="Empty state" />

The default slot holds the call-to-action(s). `icon` is any
[Kinetix icon name](/actions#icons).

---

## 3. Product tour

> **Prefer the [Product Tours module](/tours)** for anything beyond a single
> hand-mounted tour: backend-declared per-module tours with permission
> filtering, page auto-matching, driver.js rendering (spotlight overlay,
> auto-scroll, collision-aware popovers) and a `local`/`database` seen-state
> driver. The composable below stays for the lightweight, dependency-free
> case.

A dependency-free guided tour that spotlights elements by CSS selector. Tag the
targets, then mount `<KinetixTour>`:

```vue
<script setup lang="ts">
import KinetixTour from "@/components/kinetix/KinetixTour.vue";

const steps = [
  { target: "[data-tour=create]", title: "Create records", description: "Start here." },
  { target: "[data-tour=filters]", title: "Filter & search", description: "Narrow the list." },
];
</script>

<template>
  <button data-tour="create">New</button>
  <!-- ... -->
  <KinetixTour id="records-intro" :steps="steps" />
</template>
```

It **auto-starts once per `id`** (remembered in `localStorage`) and offers
next / back / skip. Set `:auto="false"` and call the exposed `start()` from a
"Show me around" button; `reset()` clears the seen flag so it can replay.

All strings are localized (`onboarding_*` / `tour_*`, en/es/fr/pt).

---

## Endpoints

The checklist registers self-service routes (team-aware prefix when
`kinetix.teams` is on):

| Method | Route                          | Name                          |
| ------ | ------------------------------ | ----------------------------- |
| `GET`  | `{prefix}/onboarding`          | `kinetix.onboarding.index`    |
| `POST` | `{prefix}/onboarding/complete` | `kinetix.onboarding.complete` |
| `POST` | `{prefix}/onboarding/dismiss`  | `kinetix.onboarding.dismiss`  |

Each user reads and updates only their own progress.
