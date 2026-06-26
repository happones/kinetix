# Membership & Provisioning

Most starter kits assume a **consumer / self-serve** model: anyone registers,
gets their own *personal team*, becomes its *owner*, and grows it by emailing
peer **invitations** that the recipient self-accepts.

Many real projects need the opposite — an **admin-provisioned / directory**
model: an organization admin adds people, those people **do not** own anything
and **cannot** create their own team; they only **activate** their account by
setting a password. Registration is closed; the only way in is provisioning.

Kinetix Membership provides that second model as a drop-in alternative to the
starter kit's `InviteMemberModal` / `TeamInvitation` flow, and it composes
cleanly with the Kinetix [Roles & Permissions](/permissions) module.

---

## 1. Two onboarding models

| Aspect | Starter-kit **Invitation** (self-serve) | Kinetix **Provisioning** (admin-controlled) |
|---|---|---|
| Who initiates | Anyone with `invitation:create` | An admin with `members.provision` |
| Account creation | Recipient **self-registers** | Admin pre-registers email + role |
| Activation | Recipient **accepts** an invite link | Member **sets a password** via signed link |
| Personal team | Auto-created on register (`is_personal=true`, `Owner`) | **None** — member joins the org team only |
| Role source | Fixed pivot enum (`Owner`/`Admin`/`Member`) | **Dynamic Kinetix role** (spatie), from a curated allow-list |
| Public registration | Open (`Features::registration()`) | **Closed** |
| Mental model | "Invite a collaborator" | "Add an employee to the directory" |

They are not competing implementations of the same idea — they are **different
onboarding philosophies**, which is why this is a *substitute* component, not a
new option on the same button.

---

## 2. Installation

Publish the provisions migration and run it:

```bash
php artisan vendor:publish --tag=kinetix-membership-migrations
php artisan migrate
```

Enable the module in `config/kinetix.php`:

```php
'membership' => [
    // Enable the provisioning module (opt-in)
    'enabled'           => env('KINETIX_MEMBERSHIP_ENABLED', false),

    // Scope provisioning to the active team (bridges currentTeam, like permissions)
    'teams'             => env('KINETIX_MEMBERSHIP_TEAMS', false),

    // The host's User model, created on activation
    'user_model'        => env('KINETIX_MEMBERSHIP_USER_MODEL', 'App\\Models\\User'),

    // Roles an admin is allowed to assign while provisioning. Anything outside
    // this list is rejected server-side — this is how you guarantee
    // "I add members but they never become admin".
    'assignable_roles'  => ['editor', 'viewer'],

    // Hours an activation link stays valid
    'activation_expiry' => env('KINETIX_MEMBERSHIP_ACTIVATION_HOURS', 72),

    // Inertia page rendered for the set-password screen
    'activation_view'   => env('KINETIX_MEMBERSHIP_ACTIVATION_VIEW', 'Kinetix/MemberActivation'),

    // Where to send a member after a successful activation
    'redirect_after'    => env('KINETIX_MEMBERSHIP_REDIRECT', '/'),

    // Optional callables to (de)attach the activated user to the host's own team
    // pivot — Kinetix never touches it directly.
    // Signature: fn ($user, MemberProvision $provision) => void
    'attach_member'     => null,
    'detach_member'     => null,
],
```

> Authorization for the management endpoints flows through Laravel's `Gate`
> exactly like `roles.manage`. With the [Roles & Permissions](/permissions)
> module enabled, just grant the `members.*` abilities to a role. Without it,
> define those gates yourself.

---

## 3. Lifecycle: provision → activate

The module uses **pre-registration + activation**: the admin stores the email
and role as a *pending provision*; no `User` is created until the person
activates. This avoids orphaned, password-less accounts.

```mermaid
sequenceDiagram
    actor Admin
    participant App as Kinetix
    actor Member
    Admin->>App: POST /members (email, role)
    App->>App: authorize members.provision + role ∈ assignable_roles
    App->>App: create pending provision (status=pending, expires_at)
    App-->>Member: signed activation link (email)
    Member->>App: open link, set name + password
    App->>App: validate signature + still pending/unexpired
    App->>App: create User, attach to team, assignRole(role)
    App->>App: mark provision active, log the member in
```

State of a single provision:

```mermaid
stateDiagram-v2
    [*] --> Pending: admin provisions
    Pending --> Active: member sets password
    Pending --> Revoked: admin revokes
    Active --> Revoked: admin revokes
    Active --> [*]
    note right of Pending
        status is only pending / active / revoked.
        "Expired" is NOT a stored status — it's derived
        from expires_at via isExpired(); a resend just
        refreshes expires_at (status stays pending).
    end note
```

---

## 4. Data model

A lightweight pending-provision table (`kinetix_member_provisions`); the `User`
is only created on activation. It carries no foreign-key constraints because the
host's `teams`/`users` schema is unknown to the package.

| Column | Notes |
|---|---|
| `team_id` | Nullable — the module works without teams |
| `email` | Unique per team (`unique(team_id, email)`) |
| `name` | Collected at activation |
| `role` | A Kinetix role name |
| `invited_by` | The provisioning admin |
| `user_id` | Set on activation |
| `status` | `pending` · `active` · `revoked` |
| `expires_at` / `activated_at` | Activation window + completion |

> The activation **token** is not stored. The link is a Laravel
> `temporarySignedRoute`, so validity is the signature plus the provision's
> `pending`, unexpired status — single-use without a bespoke token column.

---

## 5. Backend surface

The module registers a `members` feature with the permission registry, so its
abilities show up in the [permission matrix](/permissions#6-role-management-ui)
and `kinetix:permissions:sync`:

* `members.viewAny` — View members
* `members.provision` — Add / invite members
* `members.update` — Change a member's role
* `members.revoke` — Remove members

Endpoints (team-aware, mounted under the Kinetix route prefix — the same pattern
as the permission routes):

| Method | Endpoint | Ability | Description |
|---|---|---|---|
| `GET` | `{prefix}/members` | `members.viewAny` | List provisions + the assignable-role allow-list |
| `POST` | `{prefix}/members` | `members.provision` | Provision an email + role |
| `POST` | `{prefix}/members/{provision}/resend` | `members.provision` | Re-send the activation link |
| `PUT` | `{prefix}/members/{provision}` | `members.update` | Change a member's role |
| `DELETE` | `{prefix}/members/{provision}` | `members.revoke` | Revoke a member |
| `GET` | `/members/activate/{provision}` | — (signed) | Public set-password page |
| `POST` | `/members/activate/{provision}` | — (signed) | Complete activation |

The **allow-list check is the security boundary** for your requirement: even a
user who can `members.provision` cannot escalate someone to `admin` if `admin`
is not in `membership.assignable_roles`. It is re-checked at activation time too,
in case config changed between provisioning and the click.

---

## 6. Adapting the starter kit

To switch a starter-kit app from the invitation model to this one, four changes
are needed:

1. **Skip personal-team creation.** In `app/Actions/Fortify/CreateNewUser.php`
   the starter kit calls `CreateTeam::handle(..., isPersonal: true)`. Gate that
   behind a config flag (or remove it) so provisioned members don't get a
   personal team.
2. **Close public registration.** Drop `Features::registration()` from
   `config/fortify.php`; the only entry point becomes the activation link.
3. **Attach members to your team.** Point `membership.attach_member` at a
   closure that writes your team pivot — Kinetix assigns the Kinetix role, the
   host owns the membership row:
   ```php
   'attach_member' => fn ($user, $provision) =>
       $provision->team_id
           ? Team::find($provision->team_id)?->users()->attach($user->id)
           : null,
   ```
4. **Swap the UI.** Replace `InviteMemberModal` with `<KinetixMemberList>`.

---

## 7. Frontend (Vue / Inertia)

All components publish like the rest of Kinetix and read the same reactive
`kinetix_permissions` prop, so you gate them with the same `{feature}.{ability}`
keys. The role dropdown only ever offers roles in the server-enforced allow-list.

### 7.1 Members directory

`<KinetixMemberList>` is the drop-in screen: it embeds the provisioning form and
lists members (pending / active / revoked) with resend, role change and revoke.

```vue
<script setup lang="ts">
import { useKinetixCan } from "@/composables/useKinetixCan";
import KinetixMemberList from "@/components/kinetix/KinetixMemberList.vue";

const { can } = useKinetixCan();
</script>

<template>
  <KinetixMemberList v-if="can('members.provision')" />
</template>
```

Need a custom layout? Compose the presentational
`<KinetixMemberProvisioner :assignable-roles="..." @submit="...">` form with the
`useKinetixMembers()` composable (`load`, `provision`, `resend`, `updateRole`,
`revoke`, plus the reactive `provisions` / `assignableRoles`).

### 7.2 Activation page

`<KinetixMemberActivation>` is the public set-password screen. Render it from the
page named by `membership.activation_view` (default `Kinetix/MemberActivation`),
forwarding the `email` and `action` props the controller passes:

```vue
<script setup lang="ts">
import KinetixMemberActivation from "@/components/kinetix/KinetixMemberActivation.vue";

defineProps<{ email: string; action: string }>();
</script>

<template>
  <KinetixMemberActivation :email="email" :action="action" />
</template>
```

Email is fixed (it came from the provision); the member only sets a name and
password, and the form posts back to the signed `action` URL.

---

## 8. How it composes with Roles & Permissions

The two modules are **independent but complementary**:

- **Teams answer _where_** — the tenancy boundary a member belongs to.
- **Roles/Permissions answer _what_** — the abilities a member has.
- **Provisioning sits at the intersection** — a single action that *adds a user
  to a team* **and** *assigns a Kinetix role*, with the assignable set policed
  by config.

```mermaid
flowchart LR
    P[Provisioning] -->|attaches to| T[Team / tenancy]
    P -->|assigns| R[Kinetix Role]
    R -->|grants| A[Abilities]
    A -->|enforced by| G[Gate / KinetixCan]
```

You can adopt either module alone:

- **Permissions without provisioning** — keep the starter kit's invitations, but
  resolve abilities through Kinetix roles instead of the pivot enum.
- **Provisioning without permissions** — provision members and gate the
  endpoints with your own `members.*` gates; you lose the dynamic role matrix but
  the onboarding flow stands on its own.

Used together, you get the full B2B story: a closed directory where admins add
people and hand them exactly the capabilities you allow — never owner, never
admin, never a stray personal team.

---

## 9. Security considerations

- **Signed, expiring, single-use** activation links (Laravel signed URLs); never
  a plain incrementing id — validity is the signature plus the `pending` status.
- **Don't leak existence** — provisioning re-uses `updateOrCreate`, so a repeat
  on a known email looks the same as a fresh one.
- **Rate-limit** the activation and resend endpoints in your app.
- **The allow-list is enforced twice** — at provision time and again at
  activation, so a later config change can't be exploited by an old link.
- **Audit** who provisioned/revoked whom (`invited_by`, timestamps) — this is a
  privileged, organization-shaping action.
