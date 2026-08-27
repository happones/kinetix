# Entitlements

Kinetix ships four independent gating layers — [feature flags](/feature-flags),
plan capabilities and plan usage limits ([Billing](/billing)), and role
permissions ([Roles & Permissions](/permissions)). Each is right on its own.
The trouble starts when one feature sits behind **several** of them.

Written by hand, that question gets re-typed at every call site:

```php
// the controller
if (KinetixFeatures::active('discord-alerts')
    && $user->currentTeam->planAllows('alerts.discord')
    && $user->can('alerts.manage')) { … }
```

```vue
<!-- the button — and the menu item, and the empty state… -->
<KinetixFeature flag="discord-alerts">
  <KinetixPlanFeature feature="capabilities.alerts.discord">
    <KinetixCan permission="alerts.manage"><AlertsButton /></KinetixCan>
  </KinetixPlanFeature>
</KinetixFeature>
```

Three problems, all of which show up in production rather than in review:

1. **They drift.** The controller and the button are edited months apart, and
   nothing fails when they stop agreeing.
2. **They collapse three answers into one.** `false` can't tell you whether to
   hide the feature, sell it, or refuse it — so everything becomes a greyed-out
   button with no explanation.
3. **They cost.** The plan is asked once per `&&`, and the permission check —
   the expensive one — runs even when the plan already said no.

An **entitlement** is that composition, declared once under a name.

```php
KinetixEntitlements::define('alerts.discord')
    ->flag('discord-alerts')
    ->plan('alerts.discord')
    ->permission('alerts.manage');
```

```php
KinetixEntitlements::allows('alerts.discord');
```

```vue
<KinetixEntitled name="alerts.discord"><AlertsButton /></KinetixEntitled>
```

> **This does not replace the four layers.** `<KinetixCan>`,
> `<KinetixPlanFeature>` and `<KinetixFeature>` stay exactly as they are, and a
> feature behind ONE layer should keep using that layer directly. Reach for an
> entitlement when a feature is behind more than one.

---

## Configuration

```php
'entitlements' => [
    'enabled' => env('KINETIX_ENTITLEMENTS_ENABLED', false),
],
```

No migration. Nothing is read from the database at request time — declarations
are code, so `config:cache`, `route:cache` and Octane are all safe.

The flag only controls the `kinetix_entitlements` **Inertia prop** that feeds
the frontend helpers. `KinetixEntitlements::allows()` and the
`kinetix.entitled` middleware work whether or not it is on.

---

## 1. Declaring entitlements

Declare them in a service provider, next to your permissions and flags:

```php
use Happones\Kinetix\Entitlements\KinetixEntitlements;

public function boot(): void
{
    KinetixEntitlements::define('projects.create')
        ->label('Create projects')
        ->plan('projects')                                   // capabilities.projects
        ->limit('projects', [ProjectCounter::class, 'for'])  // usage.projects
        ->permission('projects.create');

    KinetixEntitlements::define('alerts.discord')
        ->flag('discord-alerts')
        ->plan('alerts.discord');
}
```

Every layer is optional, and a layer you don't declare simply passes. An
entitlement with **no** layers always allows — it's a name reserved for rules
you haven't written yet, not a locked door.

`define()` is **additive**: calling it twice with the same name returns the same
entitlement, so two packages or two providers can each contribute a layer.

### The layers

| Method | Asks | Backed by |
| --- | --- | --- |
| `->flag('name')` | Is it rolled out here? | [Feature flags](/feature-flags) |
| `->plan('api')` | Did this tenant buy it? | `features.capabilities.api` on the plan |
| `->planFeature('legacy.sso')` | Same, at a raw dot-path | any path in the features JSON |
| `->limit('projects', $count)` | Is there room left? | `features.usage.projects` vs. `$count` |
| `->permission('projects.create')` | May this user do it? | the Gate (Kinetix registry or your own) |

`->limit()` needs a callback that resolves the tenant's **current** usage; it
receives the billable:

```php
->limit('projects', fn ($billable) => $billable->projects()->count())
```

::: warning A limit callback runs a query
It is evaluated once per request per entitlement — including for the Inertia
share, i.e. on every full page load. Keep it a `COUNT` or a denormalized
counter, and use `->shared(false)` on an entitlement whose count is too
expensive to pay for on every page (it then stays server-only).
:::

::: tip Closures are fine here
Unlike the [config callbacks](/installation), entitlement declarations live in
a service provider — real code, never serialized — so a closure is safe.
`[Class::class, 'method']` and invokable class-strings work too.
:::

---

## 2. The evaluation order

Layers are evaluated **`flag → plan → limit → permission`**, stopping at the
first denial. The order is fixed, and the reasons are worth knowing:

1. **`flag` first** — the cheapest check, and the most absolute. Something that
   isn't rolled out should look like it was never built.
2. **`plan` and `limit` next** — one tenant-level answer that covers everybody
   on the team, [memoized for the request](#3-cost), and the only denial the
   user can actually act on.
3. **`permission` last** — the per-user check, the one that differs between two
   people looking at the same page, and the one that can run a **policy** (per
   row, in a table). It is asked only after the cheap, tenant-wide layers have
   had their say.

That short-circuit is not just a micro-optimization: it is why an entitlement
costs less than the hand-written `&&` chain it replaces.

---

## 3. Cost

Everything an entitlement touches is memoized for the request:

- The **plan** is resolved once per billable and answered from an in-memory
  catalog of the `plans` table, so ten plan questions cost **one** query — see
  [Billing → The plan catalog](/billing#the-plan-catalog).
- The **verdict** itself is memoized per (user × entitlement), so asking from
  the controller, from a table's actions and from the Inertia share evaluates
  once.
- The **permission** layer is skipped entirely whenever an earlier layer denied.

The one thing that is *not* free is a `->limit()` count callback — it is your
query, and it runs. See the warning above.

---

## 4. Why it was denied

A verdict carries the layer that refused, which is the whole point:

```php
$verdict = KinetixEntitlements::check('projects.create');

$verdict->allowed;      // bool
$verdict->reason;       // DenialReason::Plan | Limit | Flag | Permission | Undefined | null
$verdict->remaining;    // ?int — units left under the usage limit
$verdict->isUpsell();   // plan/limit denial → sell, don't scold
$verdict->status();     // 404 for a flag denial, 403 otherwise
```

| Reason | Means | HTTP | UI |
| --- | --- | --- | --- |
| `flag` | Not rolled out here | **404** | render nothing |
| `plan` | The plan doesn't include it | 403 | padlock + upgrade CTA |
| `limit` | At the plan's usage cap | 403 | padlock + "3 of 3 used" |
| `permission` | This user may not | 403 | render nothing |
| `undefined` | No entitlement declared under that name | 403 | render nothing |

A **flag** denial is deliberately a 404: an unreleased feature should be
indistinguishable from one that was never built, so its existence can't be
probed by watching status codes.

An **undefined** name is denied, not allowed — authorization fails closed. With
`app.debug` on, Kinetix logs a warning naming the entitlement, because the
overwhelmingly likely cause is a typo or a provider that never ran.

---

## 5. Enforcing on the server

**Middleware** — one alias replaces the `kinetix.feature:… + kinetix.plan:… +
can:…` stack, and unlike stacking them, the response matches the layer that
refused:

```php
Route::post('projects', [ProjectController::class, 'store'])
    ->middleware('kinetix.entitled:projects.create');

// Several at once — ALL must allow; the first denial decides the response.
Route::get('reports', ReportController::class)
    ->middleware('kinetix.entitled:reports.view,billing.view');
```

**In a controller**:

```php
public function store(Request $request): RedirectResponse
{
    KinetixEntitlements::authorize('projects.create');
    // …
}
```

Both take the upsell path for a plan/limit denial: a redirect to
`kinetix.billing.upgrade_url` carrying a toast, falling back to a plain 403 when
no upgrade page is configured (and always a 403 for JSON requests) — the same
behavior as the `kinetix.plan` middleware, because they share one
implementation.

> **Entitlements are not a replacement for policies.** An entitlement answers
> "may this user do this KIND of thing here" — a class-level question. "May
> they touch THIS record" is still a model policy, and the policy still owns
> the tenancy boundary. See
> [Permissions §1.5](/permissions#_1-5-enforcing-on-the-server).

---

## 6. Gating the frontend

Kinetix shares every declared entitlement, already resolved, as the
`kinetix_entitlements` prop — no host wiring.

```vue
<script setup lang="ts">
import { useKinetixEntitlement } from '@/composables/useKinetixEntitlement'
import KinetixEntitled from '@/components/kinetix/KinetixEntitled.vue'

const { allows, reason, remaining } = useKinetixEntitlement()
</script>

<template>
  <KinetixEntitled name="projects.create">
    <template #default="{ remaining }">
      <CreateProjectButton />
      <span v-if="remaining !== null">{{ remaining }} left on your plan</span>
    </template>

    <template #denied="{ reason, isUpsell, remaining }">
      <KinetixPlanLock v-if="isUpsell" variant="badge" feature-name="Projects" />
      <span v-else-if="reason === 'permission'">Read only</span>
      <!-- reason === 'flag' → render nothing -->
    </template>
  </KinetixEntitled>
</template>
```

With **no** `denied` slot, nothing renders — the safe default for both a flag
denial (leave no trace) and a permission denial (don't hint that it exists).

`names` requires several at once (`require-any` flips it to "any of"):

```vue
<KinetixEntitled :names="['reports.view', 'billing.view']">…</KinetixEntitled>
<KinetixEntitled :names="[…]" require-any>…</KinetixEntitled>
```

The composable exposes the same verdicts: `allows`, `denies`, `reason`,
`isUpsell`, `remaining`, `allowsAll`, `allowsAny`, plus the raw `verdict(name)`.
All reactive — they update when Inertia replaces the page props.

> **Frontend checks are UX, not security.** A hidden button's endpoint is still
> reachable with `curl`. Every mutation needs §5.

---

## 7. Which layer should own a rule?

The most common mistake is putting an entitlement question inside a **policy**.
Don't. The split that holds up:

| Question | Belongs in |
| --- | --- |
| Is this record in the current tenant? | the **policy** |
| May this role do this kind of thing? | the **matrix** (`$user->can(...)`), consulted by the policy |
| Did this tenant buy the feature? | an **entitlement** (`->plan()`) |
| Is there room under the plan? | an **entitlement** (`->limit()`) |
| Is it rolled out yet? | an **entitlement** (`->flag()`) |

A plan check inside a policy is wrong on three counts: the policy runs **per
record** while the plan is a **per-tenant** answer (so you multiply a constant
cost by N rows); a `bool` can't produce an upsell; and role, plan and flag
invalidate on three completely different clocks.

---

## Related docs

- [Roles & Permissions](/permissions) — the matrix behind `->permission()`, and
  why model policies must delegate to it.
- [Billing](/billing) — plans, `capabilities.*` / `usage.*`, `<KinetixPlanLock>`
  and the plan catalog that makes `->plan()` free.
- [Feature Flags](/feature-flags) — the flags behind `->flag()`.
