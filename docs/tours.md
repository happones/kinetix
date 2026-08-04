# Product Tours

Guided, spotlight-style tours for your modules: declare each tour **once in the
backend**, mount one global host in your layout, and the right tour auto-starts
— exactly once — for the users allowed to see it. Rendering is powered by
[driver.js](https://driverjs.com) (the most popular, actively-maintained tour
engine — ~5 kB, MIT) themed to the shadcn tokens by the published
`kinetix.css`, so popovers, buttons and the overlay follow your template's
design line in light and dark.

> Looking for the setup **checklist** or the lightweight dependency-free tour?
> See [Onboarding](/onboarding). This module supersedes the old
> `useKinetixTour` composable for anything backend-driven.

## 1. Setup

```bash
# .env
KINETIX_TOURS_ENABLED=true
```

Install the renderer in the host app (opt-in dependency):

```bash
php artisan kinetix:install --tours   # adds driver.js to package.json
```

Mount the host **once** in your app layout — it's renderless:

```vue
<script setup lang="ts">
import KinetixTours from '@/components/kinetix/KinetixTours.vue';
</script>

<template>
  <slot />
  <KinetixTours />
</template>
```

## 2. Declaring tours

One tour per module, from any service provider. Tag the target elements with
`data-tour` attributes in your pages:

```php
use Happones\Kinetix\Tours\KinetixTours;
use Happones\Kinetix\Tours\TourStep;

KinetixTours::tour('posts')
    ->page('Kinetix/Posts/Index')          // Inertia component (or ->url('/posts*'))
    ->permission('posts.viewAny')          // optional: Gate-checked server-side
    ->steps([
        TourStep::make('[data-tour=create]')
            ->title(__('tours.posts.create'))
            ->description(__('tours.posts.create_body'))
            ->side('bottom'),
        TourStep::make('[data-tour=filters]')
            ->title(__('tours.posts.filters')),
    ]);
```

| Method | Behaviour |
|---|---|
| `page('Kinetix/Posts/Index')` | Match by Inertia component name (`*` wildcards) — preferred, team-prefix-proof |
| `url('/posts*')` | Match by URL path (`*` wildcards) |
| `permission('posts.viewAny')` | Users the Gate denies never receive the tour (filtered server-side) |
| `auto(false)` | Never auto-start — manual launches only (help menu, replay button) |
| `TourStep::side()/align()` | driver.js popover positioning (`top/right/bottom/left`, `start/center/end`) |

Wrap step copy in `__()` — tours are user-facing strings and follow the same
[localization rule](/locale) as every developer-declared label.

## 3. Seen state: `local` vs `database`

`kinetix.tours.driver` picks where "already seen" is remembered:

| Driver | Storage | Survives | Needs |
|---|---|---|---|
| `local` (default) | Browser localStorage | The browser profile | Nothing |
| `database` | `kinetix_tour_state` per user | Devices, browsers, resets by an admin | `vendor:publish --tag=kinetix-tours-migrations && php artisan migrate` |

With `database`, the host persists through the team-aware
`{prefix}/tours/{id}/seen` endpoints (POST = seen, DELETE = re-arm). Both
finishing and dismissing a tour count as seen — tours never nag.

## 4. Manual control (the pinia store)

Anything can start or re-arm a tour through the store — a "Replay tour" item
in your help menu, for example:

```vue
<script setup lang="ts">
import { useKinetixToursStore } from '@/stores/kinetixTours';

const tours = useKinetixToursStore();
</script>

<template>
  <DropdownItem @click="tours.start('posts')">Replay the posts tour</DropdownItem>
</template>
```

| Store API | Behaviour |
|---|---|
| `start(id)` | Run a tour now (ignores seen state) |
| `hasSeen(id)` / `markSeen(id)` / `reset(id)` | Inspect / persist through the configured driver |
| `tours` / `enabled` / `activeTourId` | Reactive share state |

## 5. Theming

`<KinetixTours />` ships its own popover theme (scoped by
`popoverClass: 'kinetix-tour-popover'`, so a host using driver.js for other
purposes stays unaffected). Colors resolve through the Tailwind-level
`--color-popover` / `--color-border` / `--color-primary` / … variables, which
exist in **both** token conventions (kinetix.css HSL triplets and starter-kit
complete colors) — so the popover follows the **active theme at that moment**:
light, dark, or system (`html.dark` flips and every token shifts with it,
mid-tour included). Override the class in your CSS to fine-tune.

## 6. Config reference

```php
'tours' => [
    'enabled' => env('KINETIX_TOURS_ENABLED', false),
    'driver'  => env('KINETIX_TOURS_DRIVER', 'local'), // 'local' | 'database'
],
```
