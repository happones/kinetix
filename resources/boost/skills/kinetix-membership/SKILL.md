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
    // members from ever becoming admin.
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

Management endpoints (team-aware, gated through Laravel's Gate like `roles.manage`):

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
at provision **and** activation time. Kinetix assigns the dynamic Kinetix role; it
never touches the host's team pivot, so wire `attach_member` to write your own
membership row:

```php
'attach_member' => fn ($user, $provision) =>
    $provision->team_id
        ? Team::find($provision->team_id)?->users()->attach($user->id)
        : null,
```

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
