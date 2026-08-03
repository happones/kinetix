---
name: kinetix-membership
description: "Handles admin-provisioned membership — adding members by email + role, password activation via signed links, an assignable-role allow-list, and the members directory UI. An alternative to self-serve team invitations. Activates when provisioning members, wiring activation, gating member endpoints, or rendering the membership UI."
license: MIT
metadata:
  author: happones
---

# Kinetix Membership Development

## When to Apply

Activate this skill when:
- Enabling the admin-provisioned onboarding model instead of self-serve team invitations.
- Provisioning members (email + role) and wiring the password-activation flow.
- Gating the `members.*` endpoints/UI, or constraining which roles a provisioner may assign.
- Rendering the membership UI (`<KinetixMemberList>`, `<KinetixMemberProvisioner>`, `<KinetixMemberActivation>`) or using the `useKinetixMembers` composable.
- Attaching activated users to the host app's own team pivot via `attach_member` / `detach_member`.

## Documentation

For full details, reference `docs/membership.md` (published at https://happones.github.io/kinetix/membership).

## Common integration mistakes

> **Diagnose first.** `php artisan kinetix:doctor` reports every silent
> misconfiguration in one shot (prefix, team scoping, teamless roles,
> `attach_member`, config closures, published-file drift, duplicated i18n
> bundles) and exits non-zero on errors.

Check these **first** — they are what actually breaks integrations, and each one
fails silently:

1. **Writing your own controller under the wrong prefix.** The module's endpoints
   live at `{current_team}/{kinetix.route_prefix}/members/…` (e.g.
   `/acme/_kinetix/members`) and `<KinetixMemberList>` calls them itself. A
   controller of yours at `{current_team}/members` is never invoked — the app
   registers only the *Inertia page* route. Verify with
   `php artisan kinetix:routes members` before writing any endpoint.
2. **Leaving `attach_member` at `null` in a teams app.** Kinetix assigns the role
   and never touches the host's team pivot, so activation succeeds and the member
   belongs to **no team** — in a team-routed app they can't reach any page. Kinetix
   logs a boot warning for exactly this combination.
3. **Redefining a `kinetix_*` Inertia prop.** `HandleInertiaRequests::share()` is
   merged **over** the package's shared props, so your own `kinetix_permissions`
   key silently replaces Kinetix's and the `members.*` gating in the UI collapses.
   Share your data under your own key.
4. **Seeding the assignable roles without team context.** Under team scoping a
   role created with no team id is global (visible in every team, super-admin
   editable only), so it won't behave like the team role you expected.
   `kinetix:permissions:sync` lists teamless roles.

5. **Writing a config callback as a closure.** `assignable_roles`,
   `attach_member` and `detach_member` as `fn (...) => ...` make
   `php artisan config:cache` abort ("value at … is non-serializable"), i.e. the
   app can't deploy. Always use the serializable forms:
   `[SyncProvisionedMember::class, 'attach']` or an invokable class-string.

## Configuration

Enable the module in `config/kinetix.php` (opt-in, default off) and publish + run the migration:

```bash
php artisan vendor:publish --tag=kinetix-membership-migrations
php artisan migrate
```

```php
'membership' => [
    'enabled'           => env('KINETIX_MEMBERSHIP_ENABLED', false),
    'teams'             => env('KINETIX_MEMBERSHIP_TEAMS', false),
    'user_model'        => env('KINETIX_MEMBERSHIP_USER_MODEL', 'App\\Models\\User'),
    // The ONLY roles a provisioner may assign — the guard that keeps added
    // members from ever becoming admin. Array, closure fn ($teamId) => array,
    // or an invokable class-string (config:cache-safe).
    'assignable_roles'  => ['editor', 'viewer'],
    'activation_expiry' => env('KINETIX_MEMBERSHIP_ACTIVATION_HOURS', 72),
    'activation_view'   => env('KINETIX_MEMBERSHIP_ACTIVATION_VIEW', 'Kinetix/MemberActivation'),
    'redirect_after'    => env('KINETIX_MEMBERSHIP_REDIRECT', '/'),
    // Optional callables: fn ($user, MemberProvision $provision) => void
    'attach_member'     => null,
    'detach_member'     => null,
],
```

---

## Invitation vs Provisioning

This module is an **alternative** to the starter-kit's self-serve team invitations,
not an extension of them. Use provisioning when admins should add people who never
own anything and never create a personal team — they only activate by setting a
password. Registration stays closed; the only way in is provisioning.

---

## Backend Usage

The module registers a `members` feature with the permission registry, so its
abilities appear in the permission matrix and `kinetix:permissions:sync`:
`members.viewAny`, `members.provision`, `members.update`, `members.revoke`.

Management endpoints, gated through Laravel's Gate like `roles.manage`. `{prefix}`
is `{current_team}/_kinetix` with teams on, `_kinetix` without — **never a path of
your own** (`php artisan kinetix:routes members` prints the resolved URIs):

| Method | Endpoint | Ability |
|---|---|---|
| `GET` | `{prefix}/members` | `members.viewAny` |
| `POST` | `{prefix}/members` | `members.provision` |
| `POST` | `{prefix}/members/{provision}/resend` | `members.provision` |
| `PUT` | `{prefix}/members/{provision}` | `members.update` |
| `DELETE` | `{prefix}/members/{provision}` | `members.revoke` |

Activation endpoints are public but protected by a temporary signed URL (GET shows
the page, POST completes it — both share the same path so one signature covers both):

| Method | Endpoint |
|---|---|
| `GET` | `/members/activate/{provision}` (signed) |
| `POST` | `/members/activate/{provision}` (signed) |

**The allow-list is the security boundary.** A user who can `members.provision`
still cannot assign a role outside `membership.assignable_roles` — it is enforced
at provision **and** activation time (at activation against the *provision's*
team, since the signed URL carries no `{current_team}` segment). Kinetix assigns
the dynamic Kinetix role; it never touches the host's team pivot, so wire
`attach_member` — **required** once team scoping is on:

Teams that create their own roles in the Roles UI also need a **dynamic**
allow-list — a callback receiving the provision's team key instead of a static
array. **Write both as callable arrays, never closures**: a closure in a config
file makes `php artisan config:cache` fail, so a closure-based config is not
deployable.

```php
// config/kinetix.php → membership
'attach_member'    => [\App\Kinetix\SyncProvisionedMember::class, 'attach'],
'detach_member'    => [\App\Kinetix\SyncProvisionedMember::class, 'detach'],
'assignable_roles' => [\App\Kinetix\AssignableRoles::class, 'forTeam'],
```

```php
class SyncProvisionedMember
{
    public function attach(Model $user, MemberProvision $provision): void
    {
        if ($provision->team_id !== null) {
            Team::find($provision->team_id)?->users()->attach($user->getKey());
        }
    }
}

class AssignableRoles
{
    public function forTeam(int|string|null $teamId): array
    {
        return Role::where('team_id', $teamId)
            ->whereNotIn('name', ['super-admin', 'admin'])
            ->pluck('name')
            ->all();
    }
}
```

An invokable class-string (`AssignableRoles::class`) works too. An array is read
as a callback only when it is a `[class-string, method]` pair, so
`['editor', 'viewer']` still means a list of role names.

---

## Frontend Usage

Components publish with `--tag=kinetix-components` and read the reactive
`kinetix_permissions` prop, so gate them with the same `{feature}.{ability}` keys.

### 1. Members directory (drop-in)

`<KinetixMemberList>` embeds the provisioning form and lists members
(pending / active / revoked) with resend, role change and revoke:

```vue
<script setup lang="ts">
import { useKinetixCan } from '@/composables/useKinetixCan'
import KinetixMemberList from '@/components/kinetix/KinetixMemberList.vue'

const { can } = useKinetixCan()
</script>

<template>
  <KinetixMemberList v-if="can('members.provision')" />
</template>
```

For a custom layout, compose the presentational
`<KinetixMemberProvisioner :assignable-roles="..." @submit="...">` with the
`useKinetixMembers()` composable (`load`, `provision`, `resend`, `updateRole`,
`revoke`, plus reactive `provisions` / `assignableRoles`).

### 2. Activation page

Render `<KinetixMemberActivation :email="email" :action="action">` from the page
named by `membership.activation_view` (default `Kinetix/MemberActivation`),
forwarding the `email` and `action` props the controller passes. Email is fixed
(from the provision); the member only sets a name + password, posting back to the
signed `action` URL.

---

## Composing with Permissions

Membership and [Permissions](https://happones.github.io/kinetix/permissions) are
independent but complementary: **teams = where**, **roles/permissions = what**,
**provisioning = the intersection** (adds a user to a team AND assigns a role).
With Permissions enabled, grant the `members.*` abilities to a role; without it,
define those gates in your app.

## UUID / ULID Host Models

This feature's migration builds `user_id`, `team_id` and `invited_by` with
`Happones\Kinetix\Support\HostKeys`, which types each column after YOUR model
at migrate time (`HasUlids` -> ulid, `HasUuids` -> uuid, string `$keyType` ->
string, else bigint). Pin `kinetix.key_types.user|team` when detection cannot
see the setup; morph ids follow `kinetix.key_types.morph` (default bigint) —
set it when the referenced models use UUIDs/ULIDs. Apps migrated on an older
Kinetix have bigint columns on disk and need their own ALTER migration. Full
recipe: the `kinetix-boost` skill, section "UUID / ULID Host Models".
