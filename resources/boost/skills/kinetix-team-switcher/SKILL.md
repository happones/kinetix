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
    'labels'           => ['switch' => …, 'select' => …, 'create' => 'New account'], // keys or strings
    'redirect_after_create' => null, // route name | [Class::class, 'method'](team) → URL
    'logo'      => ['enabled' => false, 'attribute' => 'logo', 'disk' => null, 'resolver' => null, 'default' => null],
    'indicator' => ['enabled' => false, 'attribute' => null, 'colors' => [], 'resolver' => null,
                    'icon' => null, 'icons' => [], 'enum_icons' => false, 'icon_resolver' => null],
],
```

- **Left — the logo**: `logo` (URL or a path on `disk`); without one, `default`
  (a Lucide icon name, or an image URL/path), then the initials. A broken
  image falls back the same way.
- **Right — the indicator**: a dot in the color of the team's type —
  `indicator.attribute` as an enum's `getColor()`, a value `colors` maps, a CSS
  color, or a status name → theme token. An icon replaces the dot via `icon`
  (every team), `icons` (per type), `enum_icons` (the enum's `getIcon()`) or
  `icon_resolver`. Collapsed sidebar: the dot rides on the logo's corner.
- `resolver`s are callable arrays receiving the team and win over the
  attribute. Never closures in config.
- **After create**: in your create action,
  `redirect(KinetixTeams::redirectAfterCreate($team, route('teams.edit', $team)))`.

You provide `teams.switch` (e.g. a controller calling `$user->switchTeam($team)`).

## Backend

- `TeamSwitcherManager::payload()` → `{ enabled, teams:[{id,key,name,url,current,
  initials,logo,logoIcon,accent,icon}], current:{id,name,initials,logo,logoIcon,accent,icon}|null,
  createUrl, switchMethod, labels }`. `url` is `null` when the switch route is
  missing (graceful). Empty for guests / when disabled.
- Shared on every Inertia response as `kinetix_teams`.

## Frontend

```vue
<KinetixTeamSwitcher />                                          <!-- header button -->
<KinetixTeamSwitcher variant="sidebar" keep-page side="right" /> <!-- replaces the starter kit's -->
<KinetixTeamSwitcher variant="header" keep-page align="end" />
```

`<KinetixTeamAvatar :team>` draws a team's mark alone; `#create="{ label }"`
gets the configured create label.

`Users` + current-team-name trigger → dropdown of teams (active marked) + an
optional "New team". `useKinetixTeams()` → `{ teams, current, createUrl,
switchTeam }`; `switchTeam(team)` visits `team.url` (no-op for the current team).
i18n `teams_switch/select/new`.

- `keep-page` (or `switchTeam(team, { keepPage: true })`): after switching, reopen
  the same page for the new team (`/acme/x` → `/beta/x`); each team carries its
  route `key`.
- `#trigger="{ current }"` replaces the button (e.g. a sidebar menu button);
  `#create` replaces "New team" (e.g. an item opening your modal);
  `side` / `align` place the menu.
