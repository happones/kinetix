---
name: kinetix-wizard
description: "Multi-step wizards: a Wizard/Step form layout AND a standalone <KinetixWizard> page component with shadcn variants, per-step validation gating, and a route-gating middleware until the wizard is completed. Activates when building wizards, step flows, or gating access behind completion."
license: MIT
metadata:
  author: happones
---

# Kinetix Wizard Development

## When to Apply

Activate this skill when:
- Building a multi-step form (`Wizard::make()->steps([Step::make()])`).
- Mounting `<KinetixWizard>` in a page (account setup, gated onboarding flow).
- Gating routes until a user finishes a wizard (`kinetix.wizard:<slug>`).

## Documentation

For full details, reference `docs/wizard.md` (published at https://happones.github.io/kinetix/wizard).

## Form layout

```php
use Happones\Kinetix\Forms\Components\{Wizard, Step, TextInput};

Wizard::make()
    ->variant('panels') // default | simple | vertical | panels | gradient
    ->steps([
        Step::make('Account')->schema([TextInput::make('email')->required()]),
        Step::make('Profile')->icon('user')->schema([TextInput::make('name')]),
    ]);
```

Advancing is blocked until the current step's **required** fields are filled
(server validation still runs on submit). Rendered by `KinetixFormWizard` →
`KinetixWizard`.

## Standalone component

```vue
<KinetixWizard :steps="steps" variant="gradient" slug="account-setup" @finish="...">
  <template #plan>…</template>   <!-- slot named per step.key -->
  <template #team>…</template>
</KinetixWizard>
```

- `steps: { key?, label, description?, icon?, color? }[]`, `variant`, `slug?`,
  `v-model:step`, `linear` (default true), `beforeNext(fromIndex) => bool|Promise`
  (return false to block — per-step validation).
- Content slot per `step.key` or scoped `#default="{ step, index, stepKey }"`;
  `#actions="{ next, prev, finish, isFirst, isLast, busy, current }"` overrides
  the nav bar. Events: `update:step`, `step-change`, `finish`.
- **`stepLayout`** (`stepper` variant, horizontal only; default `inline`):
  `inline` (indicator + label side by side, label hidden below `sm:`),
  `stacked` (indicator on top, label always visible, truncated), or `tooltip`
  (indicator only — label/description on hover/focus via reka `Tooltip*`
  primitives; most compact for many steps on narrow viewports). PHP:
  `Wizard::make()->stepLayout('stacked')`.
- **Per-step `color`** (`stepper` variant): `{ ..., color: 'success' }` accents
  that step's indicator once active/complete (`success|danger|warning|info|
  primary|gray`, via `statusButtonClass`); upcoming steps stay neutral
  regardless. PHP: `Step::make('Account')->color('success')`.
- The horizontal `stepper` indicator sits in its own `overflow-x-auto`
  wrapper and labels `truncate` — with many steps (5-6+) or long labels it
  scrolls internally instead of breaking the page layout, across every
  `stepLayout`/`fullWidth` combination.

## Gating middleware

```php
// config/kinetix.php
'wizards' => [
    'enabled' => env('KINETIX_WIZARDS_ENABLED', false),
    'teams'   => env('KINETIX_WIZARDS_TEAMS', false),
    'gates'   => ['account-setup' => 'account.setup'], // slug => redirect route name
],
```

```bash
php artisan vendor:publish --tag=kinetix-wizards-migrations && php artisan migrate
```

```php
Route::middleware(['auth', 'kinetix.wizard:account-setup'])->group(fn () => /* gated */);
Route::get('/welcome', SetupController::class)->name('account.setup'); // NOT gated
```

- Until completed, gated routes redirect to `gates[slug]`. The middleware
  no-ops when unauthenticated, when the slug has no configured route, or when
  already on the target route (loop-safe).
- `<KinetixWizard slug=...>` marks completion on finish (via
  `useKinetixWizard().complete`). Completion is per user (or per team). Reset via
  `WizardManager::reset($user, $slug)`. Endpoints: `GET/POST {prefix}/wizards/{slug}[/complete]`.

i18n `wizard_*` (en/es/fr/pt).

## UUID / ULID Host Models

This feature's migration builds `user_id` and `team_id` with
`Happones\Kinetix\Support\HostKeys`, which types each column after YOUR model
at migrate time (`HasUlids` -> ulid, `HasUuids` -> uuid, string `$keyType` ->
string, else bigint). Pin `kinetix.key_types.user|team` when detection cannot
see the setup; morph ids follow `kinetix.key_types.morph` (default bigint) —
set it when the referenced models use UUIDs/ULIDs. Apps migrated on an older
Kinetix have bigint columns on disk and need their own ALTER migration. Full
recipe: the `kinetix-boost` skill, section "UUID / ULID Host Models".
