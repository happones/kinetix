# Team Switcher

A header dropdown to **switch the active team**. The official Laravel starter kit
has no teams concept, so this is a complete Kinetix feature — but Kinetix does
**not own your `Team` model**. It resolves the user's teams *by convention* and
shares them (each with a ready-made switch URL) via the `kinetix_teams` Inertia
prop; the component just visits that URL. So it works with whatever switch route
your app already has.

<Screenshot name="team-switcher" alt="Team switcher dropdown" />

---

## Installation

Enable it and point the config at the relations/routes your app uses:

```php
'team_switcher' => [
    'enabled' => env('KINETIX_TEAM_SWITCHER_ENABLED', true),

    // Relations on the user model.
    'teams_relation'   => 'teams',        // → the user's teams
    'current_relation' => 'currentTeam',  // → the active team

    // Team display label attribute.
    'name_attribute' => 'name',

    // Route to switch teams (receives the team's route key — slug when the
    // model defines getRouteKeyName()). The starter-kit convention is below.
    'switch_route' => env('KINETIX_TEAM_SWITCH_ROUTE', 'teams.switch'),

    // Optional "New team" route.
    'create_route' => env('KINETIX_TEAM_CREATE_ROUTE'),
],
```

Kinetix reads `auth()->user()->teams` and `->currentTeam`, builds a switch URL
per team with `route('teams.switch', $team->getRouteKey())`, and shares the lot.
You provide the switch route and the logic that sets the current team — Kinetix
never touches your team pivot or tenancy.

::: tip Already have a switch route?
If your app (or your template) defines `teams.switch` — e.g. a controller calling
`$user->switchTeam($team)` — you're done: set `switch_route` to its name and the
switcher visits it.
:::

---

## The component

```vue
<script setup lang="ts">
import KinetixTeamSwitcher from '@/components/KinetixTeamSwitcher.vue';
</script>

<template>
    <KinetixTeamSwitcher />
</template>
```

It shows the current team with a `Users` icon; the dropdown lists every team (the
active one marked), plus a **New team** entry when `create_route` is set.
Selecting a team visits its switch URL; the already-active team is a no-op.

`useKinetixTeams()` exposes `{ teams, current, createUrl, switchTeam }` for a
custom UI. Strings are localized (`teams_switch`, `teams_select`, `teams_new`;
en/es/fr/pt).

---

## Shared prop

`kinetix_teams` is shared on every Inertia response:

```ts
{
  enabled: boolean,
  teams: { id, name, url: string | null, current: boolean }[],
  current: { id, name } | null,
  createUrl: string | null,
}
```

`url` is `null` when the configured switch route doesn't exist, so the switcher
degrades gracefully. The payload is empty (`enabled: false`) for guests or when
the feature is off.
