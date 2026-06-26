# Feature Flags

Kinetix Feature Flags gate functionality for gradual rollout, A/B-style toggles,
and **plan-gating**. Define flags once; resolve them through
[`laravel/pennant`](https://laravel.com/docs/pennant) when it's installed (the
standard — with persistence, lottery and rich scopes), or a native closure
evaluator otherwise. Gate the backend (helper / middleware) and the frontend
(`useKinetixFeature` / `<KinetixFeature>`) with the same flag names.

---

## Configuration

```php
'features' => [
    'enabled' => env('KINETIX_FEATURES_ENABLED', false),
    // auto = pennant when installed, else native. pennant | native to force.
    'driver'  => env('KINETIX_FEATURES_DRIVER', 'auto'),
    // Resolve flags for the active team instead of the user.
    'teams'   => env('KINETIX_FEATURES_TEAMS', false),
],
```

No migration from Kinetix. With the pennant driver, publish pennant's own
migration if you use its `database` store (the `array` store needs nothing).

---

## 1. Defining flags

Declare flags in a service provider. A flag is a boolean or a closure that
receives the **scope** (the user, or the team when `features.teams` is on):

```php
use Happones\Kinetix\Features\KinetixFeatures;

public function boot(): void
{
    KinetixFeatures::define('new-dashboard', true);

    KinetixFeatures::define('beta-search', fn ($user) => $user?->is_staff === true);

    // Plan-gating: defer to Billing.
    KinetixFeatures::define('api-access', fn ($user) => $user?->canUseFeature('capabilities.api') ?? false);
}
```

> Plan-gating is just a flag whose resolver asks Billing — no separate
> mechanism. See [Billing](/billing) for `canUseFeature()`.

---

## 2. Gating the backend

```php
use Happones\Kinetix\Features\KinetixFeatures;

if (KinetixFeatures::active('beta-search')) {
    // ...
}
```

Or gate a route — inactive flags 404 (the route effectively doesn't exist):

```php
Route::get('/beta', BetaController::class)->middleware('kinetix.feature:beta-search');
```

---

## 3. Gating the frontend

Kinetix shares the resolved flag map as `kinetix_features`, so no host wiring is
needed. Use the composable or the gate component:

```vue
<script setup lang="ts">
import { useKinetixFeature } from '@/composables/useKinetixFeature'
import KinetixFeature from '@/components/kinetix/KinetixFeature.vue'

const { active } = useKinetixFeature()
</script>

<template>
  <NewNav v-if="active('new-dashboard')" />

  <KinetixFeature flag="beta-search">
    <BetaSearch />
    <template #denied><LegacySearch /></template>
  </KinetixFeature>
</template>
```

All checks are reactive — they update when Inertia replaces the page props.

---

## 4. Driver

`auto` (default) detects `laravel/pennant` and resolves through it; otherwise the
native evaluator runs your closures directly. Force either with
`driver = 'pennant'` / `'native'`.

- **Pennant** adds persistence, lottery (`Feature::define(..., fn () => Lottery::odds(1, 10))`)
  and rich scope serialization — Kinetix resolves flags `for()` the current scope.
- **Native** evaluates your boolean/closure definitions each request (no
  persistence, no lottery) — enough for config-driven and plan-gated flags with
  zero dependencies.

Either way the resolved map and the `<KinetixFeature>` / `useKinetixFeature`
contract are identical.
