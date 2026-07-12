# Cookie Consent

A shadcn-styled cookie consent bar. Mount it once in your layout — it shows
until the visitor accepts or declines, then remembers the choice via a plain
browser cookie (no server round-trip) and stays hidden.

This is a **simple accept/decline bar** (comparable in scope to
`spatie/laravel-cookie-consent`), not a granular per-category consent
manager — there are no separate necessary/analytics/marketing toggles.

<Screenshot name="cookie-consent" alt="Cookie consent bar" />

---

## Installation

No migration, route, or controller — it's entirely config + a client-side cookie.

```php
'cookie_consent' => [
    'enabled' => env('KINETIX_COOKIE_CONSENT_ENABLED', false),

    // Name of the browser cookie recording the visitor's choice.
    'cookie_name' => env('KINETIX_COOKIE_CONSENT_COOKIE_NAME', 'kinetix_cookie_consent'),

    // How long the choice is remembered before the bar reappears.
    'expiry_days' => env('KINETIX_COOKIE_CONSENT_EXPIRY_DAYS', 365),

    // 'bottom' | 'top'.
    'position' => env('KINETIX_COOKIE_CONSENT_POSITION', 'bottom'),

    // Optional link to your cookie/privacy policy page, shown in the bar.
    'policy_url' => env('KINETIX_COOKIE_CONSENT_POLICY_URL'),
],
```

---

## The component

Mount it once, anywhere in your root layout — it renders nothing until the
config is enabled:

```vue
<script setup lang="ts">
import KinetixCookieConsent from '@/components/KinetixCookieConsent.vue';
</script>

<template>
    <KinetixCookieConsent />
</template>
```

It takes no props — everything is driven by the `cookie_consent` config block,
shared to the frontend via the `kinetix_cookie_consent` Inertia prop.

### How visibility is resolved

Whether the visitor has already responded is resolved **entirely
client-side**: on mount, the component checks for the configured cookie
(`cookie_name`) and shows the bar only if it's absent. Clicking **Accept** or
**Decline** writes that cookie (`accepted` or `declined`) for `expiry_days`
days and hides the bar — no request to the server, no page reload.

If you need to know server-side whether a visitor has consented (e.g. to
conditionally render a script tag), read the same cookie from the incoming
request:

```php
$hasAccepted = request()->cookie(config('kinetix.cookie_consent.cookie_name')) === 'accepted';
```

---

## Localization

The bar's message, "Learn more" policy link text, and the two button labels
are all translatable (`cookie_consent_*`), localized across all supported
locales (en/es/fr/pt/zh/ja/ru).
