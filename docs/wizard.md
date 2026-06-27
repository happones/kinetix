# Wizard

Kinetix ships a multi-step **Wizard** in two forms:

- a **form layout component** (`Wizard` + `Step`) that breaks a form schema into
  steps, and
- a **standalone `<KinetixWizard>`** Vue component you can drop into any page —
  account setup, gated flows, anything — with shadcn variants and an optional
  **completion gate** middleware.

---

## 1. Wizard as a form layout

Inside a `Form` schema, wrap steps in a `Wizard`:

```php
use Happones\Kinetix\Forms\Components\Wizard;
use Happones\Kinetix\Forms\Components\Step;
use Happones\Kinetix\Forms\Components\TextInput;

Wizard::make()
    ->variant('stepper') // stepper (default) | default | simple | vertical | panels | gradient
    ->steps([
        Step::make('Account')->schema([
            TextInput::make('email')->required(),
        ]),
        Step::make('Profile')->icon('user')->description('About you')->schema([
            TextInput::make('name')->required(),
        ]),
    ])
```

Advancing is blocked until the **required** fields in the current step are
filled (server-side validation still runs on submit). Fields across all steps
are validated and saved like any other layout.

---

## 2. Standalone `<KinetixWizard>`

Use it in a page to drive a flow whose step content is whatever you want.
Provide step metadata via `:steps` and step content via a slot named after each
step's `key` (or the scoped default slot):

```vue
<script setup lang="ts">
import KinetixWizard from "@/components/KinetixWizard.vue";

const steps = [
  { key: "plan", label: "Choose a plan", icon: "credit-card" },
  { key: "team", label: "Invite your team", icon: "user" },
  { key: "done", label: "Finish" },
];

function onFinish() {
  router.visit("/dashboard");
}
</script>

<template>
  <KinetixWizard :steps="steps" variant="gradient" @finish="onFinish">
    <template #plan> …plan picker… </template>
    <template #team> …team form… </template>
    <template #done> …summary… </template>
  </KinetixWizard>
</template>
```

### Props

| Prop         | Type                                                          | Default     | Notes |
| ------------ | ------------------------------------------------------------- | ----------- | ----- |
| `steps`      | `KinetixWizardStep[]`                                         | —           | `{ key?, label, description?, icon? }` |
| `variant`    | `stepper \| default \| simple \| vertical \| panels \| gradient` | `stepper`   | Step-indicator style |
| `orientation`| `horizontal \| vertical`                                      | `horizontal`| Stepper orientation (the `stepper` variant) |
| `slug`       | `string \| null`                                              | `null`      | When set, finishing marks completion server-side (gate) |
| `step`       | `number`                                                      | `0`         | Controlled current step (`v-model:step`) |
| `linear`     | `boolean`                                                     | `true`      | Restrict indicator jumps to reached steps |
| `beforeNext` | `(fromIndex) => boolean \| Promise<boolean>`                  | —           | Return `false` / reject to block advancing (per-step validation) |

### Events & slots

- Events: `update:step`, `step-change`, `finish`.
- Slots: `#<step.key>` (or scoped `#default="{ step, index, stepKey }"`) for
  content; `#actions="{ next, prev, finish, isFirst, isLast, busy, current }"`
  to replace the Back/Next/Finish bar.

### Variants

`stepper` *(default)* is the official shadcn/Reka **Stepper** — numbered
indicators with titles, descriptions and connecting separators, built on
`reka-ui`'s Stepper primitives. Set `orientation="vertical"` for a left-rail
layout. The other designs: `default` (numbered circles + connectors), `simple`
(progress bar + counter), `vertical` (left rail), `panels` (filled pills), and
`gradient` (an eye-catching gradient-filled indicator).

<Screenshot name="wizard-stepper" alt="Wizard — stepper variant (default)" />

<Screenshot name="wizard-stepper-vertical" alt="Wizard — vertical stepper" />

```vue
<KinetixWizard :steps="steps" />                              <!-- stepper (default) -->
<KinetixWizard :steps="steps" orientation="vertical" />       <!-- vertical stepper -->
<KinetixWizard :steps="steps" variant="gradient" />           <!-- other designs -->
```

<Screenshot name="wizard-gradient" alt="Wizard — gradient variant" />

<Screenshot name="wizard-panels" alt="Wizard — panels variant" />

---

## 3. Gating routes until a wizard is completed

Make part of your app inaccessible until the user finishes a wizard.

**1.** Enable the module and map the wizard slug to the route hosting it:

```php
// config/kinetix.php
'wizards' => [
    'enabled' => env('KINETIX_WIZARDS_ENABLED', true),
    'gates'   => [
        'account-setup' => 'account.setup', // route name to redirect to
    ],
],
```

```bash
php artisan vendor:publish --tag=kinetix-wizards-migrations
php artisan migrate
```

**2.** Apply the middleware to the routes you want gated:

```php
Route::middleware(['auth', 'kinetix.wizard:account-setup'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
});

// The wizard page itself — NOT gated (the middleware lets its own route through)
Route::get('/welcome', SetupController::class)->name('account.setup');
```

Until the user completes `account-setup`, every gated route redirects them to
`account.setup`.

**3.** Finish the wizard with its `slug` so completion is persisted and the gate
opens:

```vue
<KinetixWizard slug="account-setup" :steps="steps" @finish="router.visit('/dashboard')" />
```

Completion is stored per user (or per team when `kinetix.wizards.teams` is on).
The self-service endpoints are `GET {prefix}/wizards/{slug}` (status) and
`POST {prefix}/wizards/{slug}/complete`. To re-run a wizard, call
`WizardManager::reset($user, $slug)`.

All strings are localized (`wizard_*`, en/es/fr/pt).
