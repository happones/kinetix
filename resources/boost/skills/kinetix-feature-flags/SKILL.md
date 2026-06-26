---
name: kinetix-feature-flags
description: "Feature flags for gradual rollout and plan-gating, bridging laravel/pennant (preferred) with a native fallback. Activates when defining flags via KinetixFeatures, gating routes/UI, or plan-gating with Billing."
license: MIT
metadata:
  author: happones
---

# Kinetix Feature Flags Development

## When to Apply

Activate this skill when:
- Defining flags with `KinetixFeatures::define()`.
- Gating the backend (`KinetixFeatures::active()` / the `kinetix.feature`
  middleware) or the frontend (`useKinetixFeature` / `<KinetixFeature>`).
- Plan-gating a flag through Billing's `canUseFeature()`.

## Documentation

For full details, reference `docs/feature-flags.md` (published at https://happones.github.io/kinetix/feature-flags).

## Configuration

```php
'features' => [
    'enabled' => env('KINETIX_FEATURES_ENABLED', false),
    'driver'  => env('KINETIX_FEATURES_DRIVER', 'auto'), // auto | pennant | native
    'teams'   => env('KINETIX_FEATURES_TEAMS', false),
],
```

No Kinetix migration. With `laravel/pennant` installed the `auto` driver uses it;
otherwise a native closure evaluator runs.

---

## Backend Usage

Define flags in a provider (a bool or a closure receiving the scope — user, or
team when `features.teams` is on):

```php
use Happones\Kinetix\Features\KinetixFeatures;

KinetixFeatures::define('new-dashboard', true);
KinetixFeatures::define('beta-search', fn ($user) => $user?->is_staff === true);
// Plan-gating — defer to Billing:
KinetixFeatures::define('api-access', fn ($user) => $user?->canUseFeature('capabilities.api') ?? false);
```

Gate code or routes:

```php
KinetixFeatures::active('beta-search');                 // bool
Route::get('/beta', ...)->middleware('kinetix.feature:beta-search'); // 404 when inactive
```

- **Driver** (`FeatureManager::usesPennant()`): `auto` = pennant when its
  `Feature` class exists, else native; `pennant`/`native` force it. Pennant adds
  persistence/lottery/scopes; native evaluates the closure each request. Same
  contract either way.
- The resolved flag map is shared as the `kinetix_features` Inertia prop when the
  module is enabled.

---

## Frontend Usage

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

Reactive (updates with Inertia prop changes). Mirrors `<KinetixCan>`.
