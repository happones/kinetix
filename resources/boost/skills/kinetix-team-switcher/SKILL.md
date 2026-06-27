---
name: kinetix-team-switcher
description: "A header dropdown to switch the active team. Convention-based and model-agnostic — resolves the user's teams + a switch URL each and shares them via kinetix_teams. Activates when adding a team/tenant switcher or multi-team UI."
license: MIT
metadata:
  author: happones
---

# Kinetix Team Switcher Development

## When to Apply

Activate this skill when:
- Adding a team / tenant / workspace switcher to the header.
- Surfacing the user's teams and an active-team indicator in the SPA.

## Documentation

For full details, reference `docs/team-switcher.md` (published at https://happones.github.io/kinetix/team-switcher).

## Key idea

Kinetix does **not** own your `Team` model. It reads the user's teams by the
convention you configure and builds a switch URL per team from your route; the
component just visits it. Switching itself stays in your app.

## Configuration

```php
'team_switcher' => [
    'enabled' => env('KINETIX_TEAM_SWITCHER_ENABLED', false),
    'teams_relation'   => 'teams',        // user → teams
    'current_relation' => 'currentTeam',  // user → active team
    'name_attribute'   => 'name',
    'switch_route'     => env('KINETIX_TEAM_SWITCH_ROUTE', 'teams.switch'), // route(name, $team->getRouteKey())
    'create_route'     => env('KINETIX_TEAM_CREATE_ROUTE'),                 // optional "New team"
],
```

You provide `teams.switch` (e.g. a controller calling `$user->switchTeam($team)`).

## Backend

- `TeamSwitcherManager::payload()` → `{ enabled, teams:[{id,name,url,current}],
  current:{id,name}|null, createUrl }`. `url` is `null` when the switch route is
  missing (graceful). Empty for guests / when disabled.
- Shared on every Inertia response as `kinetix_teams`.

## Frontend

```vue
<KinetixTeamSwitcher />
```

`Users` + current-team-name trigger → dropdown of teams (active marked) + an
optional "New team". `useKinetixTeams()` → `{ teams, current, createUrl,
switchTeam }`; `switchTeam(team)` visits `team.url` (no-op for the current team).
i18n `teams_switch/select/new`.
