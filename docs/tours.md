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

One tour per module, from any service provider. Point steps at Kinetix's own
hooks — every rendered action carries `data-action="<name>"` (see
[Actions](/actions#stable-hooks-data-action)) — or tag your own elements with
`data-tour` attributes:

```php
use Happones\Kinetix\Tours\KinetixTours;
use Happones\Kinetix\Tours\TourStep;

KinetixTours::tour('posts')
    ->page('Kinetix/Posts/Index')          // Inertia component (or ->url('/posts*'))
    ->permission('posts.viewAny')          // optional: Gate-checked server-side
    ->version(2)                           // optional: re-show after a content change
    ->steps([
        TourStep::make('[data-action="create"]')
            ->titleKey('tours.posts.create')
            ->descriptionKey('tours.posts.create_body')
            ->side('bottom'),
        TourStep::make('[data-tour=filters]')
            ->titleKey('tours.posts.filters'),
    ]);
```

| Method | Behaviour |
|---|---|
| `page('Kinetix/Posts/Index')` | Match by Inertia component name (`*` wildcards) — preferred, team-prefix-proof |
| `url('/posts*')` | Match by URL path (`*` wildcards) |
| `permission('posts.viewAny')` | Users the Gate denies never receive the tour (filtered server-side) |
| `auto(false)` | Never auto-start — manual launches only (help menu, replay button) |
| `version(2)` | Content changed: people who saw an older version see it again. The id stays (see below) |
| `TourStep::titleKey()/descriptionKey($key, $replace = [])` | Step copy as translation keys, translated per request in the viewer's language |
| `TourStep::title()/description()` | Step copy as ready-made strings |
| `TourStep::side()/align()` | driver.js popover positioning (`top/right/bottom/left`, `start/center/end`) |

### Step copy in the viewer's language

Tours are declared in a provider's `boot()`, **before** the locale middleware
runs — so `->title(__('tours.posts.create'))` translates into the app's
language, and an English-speaking user on a Spanish app sees Spanish. Give the
keys instead: `titleKey()` / `descriptionKey()` are translated when the tour is
sent with each response, after the user's locale is applied. Tours follow the
same [localization rule](/locale) as every developer-declared label.

### Versions

Bump `version()` when a tour changes enough that people should see it again.
"Seen" is stored per version (`posts` for version 1, `posts@v2` from then on),
so the id — and every `start('posts')`, URL and translation that names it —
stays the same. Tours declared before versions existed keep their state.

A step can point at something taller than the screen — a whole table. The
tour then highlights its top, down to what fits with the message, so the page
doesn't jump and the message stays beside what it describes. For the sharpest
spotlight, still point a step at the part you talk about (the toolbar, the
first column) rather than the whole page section.

## 3. Seen state: `local` vs `database`

`kinetix.tours.driver` picks where "already seen" is remembered:

| Driver | Storage | Survives | Needs |
|---|---|---|---|
| `local` (default) | Browser localStorage | The browser profile | Nothing |
| `database` | `kinetix_tour_state` per user | Devices, browsers, resets by an admin | `vendor:publish --tag=kinetix-tours-migrations && php artisan migrate` |

With `database`, the host persists through the team-aware
`{prefix}/tours/{id}/seen` endpoints (POST = seen, DELETE = re-arm). Both
finishing and dismissing a tour count as seen — tours never nag.

## 4. Replaying a tour: `<KinetixTourTrigger />`

Drop it in your layout's header or help menu. It replays the tour declared for
the **current page** — seen or not, auto-start or `auto(false)` — and renders
nothing on a page without one, so it can sit there unconditionally:

```vue
<KinetixTourTrigger />                       <!-- "Show me around this page" -->
<KinetixTourTrigger icon-only />             <!-- just the icon, labelled for screen readers -->
<KinetixTourTrigger tour="setup-employees" /> <!-- a specific tour -->

<!-- Inside a menu: render your own item -->
<KinetixTourTrigger v-slot="{ start, label }">
  <DropdownMenuItem @select="start">{{ label }}</DropdownMenuItem>
</KinetixTourTrigger>
```

Props: `tour`, `label`, `iconOnly`, `variant` (default `ghost`), `size`. The
`<KinetixTours />` host must be mounted — it runs the tour.

## 5. Manual control (the pinia store)

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
| `tourFor(component, path)` | The tour declared for a page, seen or not (what the trigger uses) |
| `hasSeen(id)` / `markSeen(id)` / `reset(id)` | Inspect / persist through the configured driver |
| `tours` / `enabled` / `activeTourId` | Reactive share state |

## 6. Automated browsers (E2E tests)

A tour that auto-starts during a Playwright, Selenium or Cypress run puts an
overlay over the page and swallows the test's clicks. With
`kinetix.tours.skip_when_automated` (default `true`), tours **don't auto-start**
when the browser reports `navigator.webdriver`. Manual launches
(`<KinetixTourTrigger />`, `store.start()`) still run.

A test of the tour itself turns auto-start back on, either way:

```js
await page.goto('/posts?kinetix-tours=1');
// or, before navigating:
await page.addInitScript(() => localStorage.setItem('kinetix.tours.automated', '1'));
```

Set `KINETIX_TOURS_SKIP_WHEN_AUTOMATED=false` to never skip.

## 7. Theming

`<KinetixTours />` ships its own popover theme (scoped by
`popoverClass: 'kinetix-tour-popover'`, so a host using driver.js for other
purposes stays unaffected). Colors resolve through the Tailwind-level
`--color-popover` / `--color-border` / `--color-primary` / … variables, which
exist in **both** token conventions (kinetix.css HSL triplets and starter-kit
complete colors) — so the popover follows the **active theme at that moment**:
light, dark, or system (`html.dark` flips and every token shifts with it,
mid-tour included). Override the class in your CSS to fine-tune.

## 8. Config reference

```php
'tours' => [
    'enabled'             => env('KINETIX_TOURS_ENABLED', false),
    'driver'              => env('KINETIX_TOURS_DRIVER', 'local'), // 'local' | 'database'
    'skip_when_automated' => (bool) env('KINETIX_TOURS_SKIP_WHEN_AUTOMATED', true),
],
```
