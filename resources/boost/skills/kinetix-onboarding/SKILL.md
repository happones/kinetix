---
name: kinetix-onboarding
description: "First-run onboarding: a backend-driven setup checklist (auto/manual steps, per-user progress), a reusable empty-state block, and a dependency-free product tour. Activates when declaring checklist steps, mounting the checklist/empty-state/tour, or wiring first-run UX."
license: MIT
metadata:
  author: happones
---

# Kinetix Onboarding Development

## When to Apply

Activate this skill when:
- Declaring checklist steps (`KinetixOnboarding::step`) or configuring `kinetix.onboarding`.
- Mounting `<KinetixOnboardingChecklist>` / `<KinetixEmptyState>` / `<KinetixTour>`.
- Using `useKinetixOnboarding` or `useKinetixTour`.

## Documentation

For full details, reference `docs/onboarding.md` (published at https://happones.github.io/kinetix/onboarding).

## Configuration

```bash
php artisan vendor:publish --tag=kinetix-onboarding-migrations
php artisan migrate
```

```php
'onboarding' => [
    'enabled' => env('KINETIX_ONBOARDING_ENABLED', false),
    'teams'   => env('KINETIX_ONBOARDING_TEAMS', false), // per-team vs per-user progress
],
```

---

## Checklist (backend-driven)

```php
use Happones\Kinetix\Onboarding\KinetixOnboarding;

KinetixOnboarding::step('verify-email', 'Verify your email')
    ->description('…')->cta('Resend', '/email/verify')->icon('mail')
    ->completedUsing(fn ($user) => $user->hasVerifiedEmail()); // auto-detected

KinetixOnboarding::step('read-docs', 'Read the quickstart');   // manual (Mark as done)
```

- A step with `completedUsing` is **auto** (completion computed live, never
  stored); without it the step is **manual** (persisted when the user ticks it).
- `OnboardingManager::for($user)` merges declared steps with persisted manual
  completions + live auto-detection → `OnboardingData` (steps, completedCount,
  total, complete, dismissed). `complete($user,$key)` / `dismiss($user)` persist
  to one `kinetix_onboarding` row per user (per team when `onboarding.teams`).
- **Self-service** (no admin ability): `GET {prefix}/onboarding`,
  `POST {prefix}/onboarding/complete`, `POST {prefix}/onboarding/dismiss`.

```vue
<KinetixOnboardingChecklist />            <!-- progress bar, CTAs, mark-done, dismiss -->
```

`useKinetixOnboarding()` → `{ state, load, complete, dismiss }`. The card hides
when dismissed and (default) when complete; `:hide-when-complete="false"` keeps it.

---

## Empty states (frontend-only)

```vue
<KinetixEmptyState icon="user" :title="…" :description="…">
  <button>Invite</button>   <!-- default slot = CTAs -->
</KinetixEmptyState>
```

Pure presentational; `icon` is any Kinetix icon name.

---

## Product tour (frontend-only)

```vue
<button data-tour="create">New</button>
<KinetixTour id="records-intro" :steps="[{ target: '[data-tour=create]', title: '…' }]" />
```

- Dependency-free; spotlights targets by CSS selector + positioned tooltip
  (next/back/skip). **Auto-starts once per `id`** (localStorage). `:auto="false"`
  + exposed `start()`/`reset()` for a manual "show me around"/replay.
- `useKinetixTour(id, steps)` → `{ active, current, index, isFirst, isLast,
  start, startOnce, next, prev, finish, skip, reset, hasSeen }`.

i18n `onboarding_*` / `tour_*` (en/es/fr/pt). Tests: `OnboardingTest`,
`useKinetixOnboarding.spec.ts`, `useKinetixTour.spec.ts`.
