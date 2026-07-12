---
name: kinetix-cookie-consent
description: "A shadcn-styled, config-driven cookie consent bar (accept/decline, comparable in scope to spatie/laravel-cookie-consent). Mount <KinetixCookieConsent /> once in your layout. Activates when adding a cookie/consent banner or GDPR cookie notice."
license: MIT
metadata:
  author: happones
---

# Kinetix Cookie Consent Development

## When to Apply

Activate this skill when:
- Adding a cookie consent / cookie notice banner.
- Mounting `<KinetixCookieConsent>`.

## Documentation

For full details, reference `docs/cookie-consent.md` (published at https://happones.github.io/kinetix/cookie-consent).

## Installation & Configuration

No migration, route, or controller — entirely config + a client-side cookie.

```php
'cookie_consent' => [
    'enabled'     => env('KINETIX_COOKIE_CONSENT_ENABLED', false),
    'cookie_name' => env('KINETIX_COOKIE_CONSENT_COOKIE_NAME', 'kinetix_cookie_consent'),
    'expiry_days' => env('KINETIX_COOKIE_CONSENT_EXPIRY_DAYS', 365),
    'position'    => env('KINETIX_COOKIE_CONSENT_POSITION', 'bottom'), // 'bottom' | 'top'
    'policy_url'  => env('KINETIX_COOKIE_CONSENT_POLICY_URL'),
],
```

**Scope**: a simple accept/decline bar — not a granular per-category (necessary/
analytics/marketing) consent manager. If a project needs conditional script
loading per category, that's a bigger feature to build from scratch, not an
option on this component.

## Frontend

```vue
<KinetixCookieConsent />
```

Zero props — mount once anywhere in the root layout. `useKinetixCookieConsent()`
→ `{ config, visible, checkConsent, accept, decline }`.

- **Visibility is resolved entirely client-side**: on mount, `checkConsent()`
  reads the configured cookie (`cookie_name`); the bar shows only if it's
  absent. `accept()`/`decline()` write `accepted`/`declined` for `expiry_days`
  days — no server round-trip, no page reload.
- To read consent server-side (e.g. to conditionally render a script tag):
  `request()->cookie(config('kinetix.cookie_consent.cookie_name')) === 'accepted'`.
- i18n `cookie_consent_message`/`cookie_consent_policy_link`/
  `cookie_consent_accept`/`cookie_consent_decline`.
