---
name: kinetix-alerts
description: "In-page status alerts (<KinetixAlert>) and server-flashed alerts (KinetixFlash::alert + <KinetixFlashAlerts>, one-shot / keep / untilDismissed): success/danger/warning/info/primary/gray on soft/outline/accent surfaces, close modes (hide/session/device/permanent, timed, \"don't show again\"), named transition presets that stop under reduced motion, and screen-reader/colorblind-safe semantics. Activates when showing an inline notice, callout, warning box or dismissible banner inside a page."
license: MIT
metadata:
  author: happones
---

# Kinetix Alerts Development

## When to Apply

Activate this skill when:
- Showing an inline notice, callout, warning or error box inside a page.
- Making a notice closable for this page, this tab, this browser, or for good.
- Animating something that appears/disappears (reuse the transition presets).

For transient feedback after an action use a toast (`KinetixFlash::success()`
or `Notification::make()`), not an alert. For product news use announcements.

## Documentation

For full details, reference `docs/alerts.md` (published at https://happones.github.io/kinetix/alerts).

## The component

```vue
<KinetixAlert
    color="warning"
    variant="soft"
    :title="t('billing.trial_ending_title')"
    :description="t('billing.trial_ending_body')"
    dismissible
    dismiss-mode="session"
    dismiss-key="trial-ending"
    dont-show-again
    :persist="saveDismissal"
    transition="collapse"
>
    <template #actions>…</template>
</KinetixAlert>
```

`color`: success · danger · warning · info · primary · gray. `variant`: soft ·
outline · accent. `dismiss-mode`: hide · session · device · permanent
(`persist` = `(key) => Promise` stores a permanent close). `transition`: fade ·
slide-down · slide-up · scale · collapse · none.

- The color goes on the surface and the icon only. Title and body stay on the
  foreground tokens, which keeps the contrast valid in both themes. Surface
  classes come from `statusAlertClass(color, variant)` in
  `useKinetixStatusColor`. Never hand-roll an alert box: build on
  `KinetixAlert`, or on `statusAlertClass` with the `primitives/Alert*` parts.
- `role="auto"`: `danger` → `alert`, other colors → `status`. `region` uses the
  title as its accessible name. A translated, visually-hidden prefix
  ("Warning:") comes before the title (`sr-label` overrides it, `false` drops
  it). The title is a `<p>` unless `heading-level` is set.
- Closing: `dismiss-key` is required beyond `hide`. Closes are stored per user
  (`kinetix.dismissed:{userId}:{key}`). `device` syncs across tabs.
  `dismiss-duration` (ms) brings a `session`/`device` close back.
  `permanent` without `persist` falls back to `device`. A rejected `persist`
  re-opens the alert and emits `dismiss-error`. `v-model:open` back to `true`
  clears a stored close.
- On close, focus moves to `focusableNear()` and an announcement says whether
  the close was a dismissal or a hide-for-now.
- The first render doesn't animate unless `appear` is set.

## Flashed from the server

`<KinetixFlashAlerts />` (mounted once, where page messages belong) renders the
alerts a controller flashes:

```php
use Happones\Kinetix\Flash\KinetixFlash;

KinetixFlash::alert(__('billing.payment_failed'), 'danger')   // sends itself at statement end
    ->description(__('billing.payment_failed_body'));          // ->variant() ->icon() ->dismissible(false) ->id()

KinetixFlash::alert(__('auth.verify_email'), 'warning')->id('verify-email')->untilDismissed();
KinetixFlash::alert(__('onboarding.welcome'), 'success')->keep(2);   // next page + 2 more visits
KinetixFlash::forget('verify-email');                          // withdraw (not "closed by user")
```

- Default = one-shot over Inertia's `flash.kinetix.alerts`, never in history.
  The outlet collects them from the `flash` event, not from `page.flash`,
  because a poll or partial reload replaces that with `{}`. They stay while
  the path is the same and are cleared on `navigate` to another path.
- `keep(n)` / `untilDismissed()` → session (`kinetix_flash_alerts`) → shared
  prop `kinetix_alerts`, each with a server-made `dismissUrl`
  (`POST {prefix}/flash/{id}/dismiss`, `web` only, no team segment, guest-safe).
  A prefetch doesn't count as a showing. A closed stable `id` is remembered
  for the session, so re-sending it every request is safe.
- Client one-shot: `useKinetixFlash().alert(title, { color, description })`.
  `onKinetixFlash(handler)` gives the current page's flash and then every new
  one (it returns the unsubscribe).
- One-shot arrivals are announced (except `danger`, already `role="alert"`).

## Composables

- `useKinetixDismissal(key, { mode, duration, persist })` →
  `{ dismissed, dismiss(mode?), restore() }`. `persist` must be a plain
  function, never a getter (`toValue()` would call it).
- `useKinetixDismissalStore()` → `{ isDismissed, remember(key, scope, duration?), forget }`
  for many keys at once (the announcement banner keys each entry as
  `announcement:{id}`).
- `useKinetixTransition(preset)` → `<Transition v-bind>` props, `{ css: false }`
  under reduced motion. `collapse` needs a `grid` element with a `min-h-0`
  child.
- `useKinetixReducedMotion()`: OS setting, user preference, or
  `kinetix.motion = 'reduced'`.

i18n `alert_*` (7 locales). Tests: `KinetixAlert.spec.ts`,
`KinetixFlashAlerts.spec.ts`, `useKinetixFlash.spec.ts`,
`useKinetixDismissal.spec.ts`, `useKinetixTransition.spec.ts`,
`useKinetixReducedMotion.spec.ts`, `KinetixFlashTest`, `MotionShareTest`.
