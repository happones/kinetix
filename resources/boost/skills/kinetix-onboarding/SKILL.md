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
    'share'   => env('KINETIX_ONBOARDING_SHARE', true),  // state on every Inertia response
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

// Request-dependent CTA URLs (current team, tenant prefixes): pass a Closure —
// steps register in boot(), so a plain string can't reach request state.
KinetixOnboarding::step('invite-team', 'Invite a teammate')
    ->cta('Invite', fn ($user) => route('teams.members', $user->currentTeam));
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
<KinetixOnboardingChecklist />                      <!-- card: progress bar, CTAs, mark-done, dismiss -->
<KinetixOnboardingChecklist variant="sidebar" />    <!-- condensed block for a shadcn <SidebarFooter> -->
```

`useKinetixOnboarding()` → `{ state, load, complete, dismiss }`. The card hides
when dismissed and (default) when complete; `:hide-when-complete="false"` keeps it.

**Payload, not fetch.** With `onboarding.share` on (default) the state rides on
every Inertia response as **`kinetix_onboarding`**, so mounting the checklist
costs no request; `load()` is a no-op then (`load(true)` forces a refetch), and
`complete`/`dismiss` results win over the payload until the next response. The
cost is one progress-row read + every `completedUsing` callback **per response**
— keep them cheap. `for()` is a pure read: no row is written until the user
completes a manual step or dismisses.

**`variant`** (`card` default | `sidebar`) — same component, same state, same
endpoints; only the presentation differs. The `sidebar` variant drops step
descriptions, shows a terse `1 of 3` counter + hairline bar, makes the leading
circle the mark-done control and the row itself the CTA link, and carries
shadcn's `group-data-[collapsible=icon]:hidden` so an icon-collapsed rail hides
it (harmless outside a sidebar). Mount it in the starter kit's `AppSidebar.vue`
above the user menu; keep the checklist to ~6 steps at that width.

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

## UUID / ULID Host Models

This feature's migration builds `user_id` and `team_id` with
`Happones\Kinetix\Support\HostKeys`, which types each column after YOUR model
at migrate time (`HasUlids` -> ulid, `HasUuids` -> uuid, string `$keyType` ->
string, else bigint). Pin `kinetix.key_types.user|team` when detection cannot
see the setup; morph ids follow `kinetix.key_types.morph` (default bigint) —
set it when the referenced models use UUIDs/ULIDs. Apps migrated on an older
Kinetix have bigint columns on disk and need their own ALTER migration. Full
recipe: the `kinetix-boost` skill, section "UUID / ULID Host Models".
