---
name: kinetix-entitlements
description: "Composes the four gating layers (feature flags, plan capabilities, plan usage limits, role permissions) under one declared name that reports WHICH layer refused. Activates when a feature is gated by more than one layer, when declaring entitlements, or when a hand-written flag && plan && can() chain appears."
license: MIT
metadata:
  author: happones
---

# Kinetix Entitlements Development

## When to Apply

Activate this skill when:
- A feature sits behind **more than one** gate — a flag AND a plan, a plan AND
  a permission, a usage limit AND a role.
- You are about to write `KinetixFeatures::active(...) && $team->planAllows(...) && $user->can(...)`,
  or nest `<KinetixFeature>` inside `<KinetixPlanFeature>` inside `<KinetixCan>`.
- Declaring entitlements with `KinetixEntitlements::define()`.
- Gating a route with `kinetix.entitled`, or the UI with
  `useKinetixEntitlement` / `<KinetixEntitled>`.

**Do NOT use an entitlement for a single-layer gate.** One flag → `<KinetixFeature>`.
One permission → `<KinetixCan>`. One plan capability → `<KinetixPlanFeature>`.
Entitlements exist for the composition, not to replace the layers.

## Documentation

For full details, reference `docs/entitlements.md` (published at https://happones.github.io/kinetix/entitlements).

## Configuration

```php
'entitlements' => [
    'enabled' => env('KINETIX_ENTITLEMENTS_ENABLED', false),
],
```

No migration. The flag only controls the `kinetix_entitlements` Inertia prop;
`KinetixEntitlements::allows()` and the `kinetix.entitled` middleware work
regardless. Declarations are code, so `config:cache` / Octane are safe.

---

## Declaring (REQUIRED shape)

```php
use Happones\Kinetix\Entitlements\KinetixEntitlements;

KinetixEntitlements::define('projects.create')
    ->label('Create projects')
    ->flag('projects-v2')                                // optional
    ->plan('projects')                                   // capabilities.projects
    ->limit('projects', [ProjectCounter::class, 'for'])  // usage.projects
    ->permission('projects.create');                     // the Gate ability
```

- Every layer is optional; an undeclared layer passes. **No layers = always allows.**
- `define()` is additive — the same name called twice returns the same object,
  so two providers can each contribute a layer.
- `->plan('api')` prepends `capabilities.`; `->planFeature('legacy.sso')` takes a
  RAW dot-path for features JSON that doesn't follow the convention.
- `->limit($key, $count)` — `$count` receives the billable and **runs a query**.
  Keep it a `COUNT` or a denormalized counter. Use `->shared(false)` to keep an
  expensive one off the Inertia share (it stays server-only).
- Closures ARE allowed here (a service provider is code, never serialized) —
  unlike `config/kinetix.php` callbacks, which must be `[Class::class, 'method']`.

## Evaluation order (fixed — do not re-implement it by hand)

`flag → plan → limit → permission`, short-circuiting at the first denial.

1. `flag` first: cheapest and most absolute — an unreleased feature 404s, so its
   existence can't be probed.
2. `plan` / `limit`: one memoized, tenant-level answer; the denial the user can
   act on (upgrade).
3. `permission` last: the per-user check, and the one that can run a **policy**
   (per row, in a table). It never runs once a cheaper layer refused.

That short-circuit is why an entitlement costs LESS than the `&&` chain it
replaces.

## The verdict (the whole point)

```php
$verdict = KinetixEntitlements::check('projects.create');
$verdict->allowed;      // bool
$verdict->reason;       // DenialReason::Flag|Plan|Limit|Permission|Undefined|null
$verdict->remaining;    // ?int — units left under the usage limit
$verdict->isUpsell();   // plan/limit → sell it
$verdict->status();     // 404 for a flag denial, 403 otherwise
```

| Reason | HTTP | UI |
| --- | --- | --- |
| `flag` | **404** | render nothing |
| `plan` / `limit` | 403 | padlock + upgrade CTA |
| `permission` | 403 | render nothing |
| `undefined` | 403 | render nothing |

An **undefined name is DENIED** (authorization fails closed) and logs a warning
with `app.debug` on — the cause is almost always a typo or a provider that
never ran.

## Enforcing on the server (REQUIRED for every mutation)

```php
// One alias replaces kinetix.feature: + kinetix.plan: + can: — and the
// response matches the layer that refused.
Route::post('projects', ...)->middleware('kinetix.entitled:projects.create');
Route::get('reports', ...)->middleware('kinetix.entitled:reports.view,billing.view');

// Imperative twin, inside a controller:
KinetixEntitlements::authorize('projects.create');
```

Plan/limit denials take the upsell redirect to `kinetix.billing.upgrade_url`
(403 for JSON, or when no upgrade URL is set) — shared with `kinetix.plan`, so
the two can never behave differently.

## Entitlements do NOT replace policies

An entitlement answers a **class-level** question ("may this user do this KIND
of thing here"). "May they touch THIS record" is still a model policy, and the
**policy still owns the tenancy boundary**. See the `kinetix-permissions` skill.

Never put a plan check inside a policy: the policy runs per RECORD while the
plan is a per-TENANT answer, a `bool` can't produce an upsell, and role / plan /
flag invalidate on three different clocks.

## Frontend

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
      <span v-if="remaining !== null">{{ remaining }} left</span>
    </template>
    <template #denied="{ reason, isUpsell }">
      <KinetixPlanLock v-if="isUpsell" variant="badge" />
      <span v-else-if="reason === 'permission'">Read only</span>
    </template>
  </KinetixEntitled>
</template>
```

- No `denied` slot = render nothing (the safe default for flag and permission
  denials).
- `:names="[...]"` requires all; add `require-any` for any-of.
- An entitlement missing from the share resolves to DENIED — fails closed like
  the server.
- Display gating only; the server still enforces every mutation.

## Cost

- The plan is resolved once per billable per request from the in-memory plan
  catalog: ten plan questions = **one** `plans` query (`kinetix-billing` skill).
- Verdicts are memoized per (user × entitlement) — controller, table actions and
  the Inertia share evaluate once between them.
- The only thing that is not free is a `->limit()` count callback. It is your
  query, and it runs once per request per entitlement (including for the share).

## Files

- `src/Entitlements/{Entitlement,EntitlementRegistry,KinetixEntitlements,Verdict,DenialReason}.php`
- `src/Entitlements/Middleware/EnsureEntitled.php` · alias `kinetix.entitled`
- `resources/js/components/KinetixEntitled.vue` · `composables/useKinetixEntitlement.ts`
