---
name: kinetix-webhooks
description: "Outbound webhooks: customers subscribe endpoints to platform events; signed, queued, retried, SSRF-guarded delivery with a logged dashboard. Activates when declaring/firing webhook events, managing endpoints, or verifying signatures."
license: MIT
metadata:
  author: happones
---

# Kinetix Webhooks Development

## When to Apply

Activate this skill when:
- Declaring subscribable events (`KinetixWebhooks::events`) or firing them
  (`KinetixWebhooks::fire`).
- Mounting `<KinetixWebhookManager>` / using `useKinetixWebhooks`.
- Working on signing, SSRF validation, retries, or delivery logs.

## Documentation

For full details, reference `docs/webhooks.md` (published at https://happones.github.io/kinetix/webhooks).

## Configuration

```bash
php artisan vendor:publish --tag=kinetix-webhooks-migrations
php artisan migrate
```

```php
'webhooks' => [
    'enabled'        => env('KINETIX_WEBHOOKS_ENABLED', false),
    'driver'         => env('KINETIX_WEBHOOKS_DRIVER', 'auto'), // auto | spatie | native
    'teams'          => env('KINETIX_WEBHOOKS_TEAMS', false),
    'allow_private'  => env('KINETIX_WEBHOOKS_ALLOW_PRIVATE', false), // dev/testing only
    'timeout'        => env('KINETIX_WEBHOOKS_TIMEOUT', 10),
    'tries'          => env('KINETIX_WEBHOOKS_TRIES', 3),
    'retention_days' => env('KINETIX_WEBHOOKS_RETENTION_DAYS', 30),
],
```

Deliveries are queued — run a worker.

---

## Backend Usage

```php
use Happones\Kinetix\Webhooks\KinetixWebhooks;

KinetixWebhooks::events(['order.created' => 'Order created']); // provider boot
KinetixWebhooks::fire('order.created', ['id' => $order->id]);  // domain code
```

- Only **registered** events fire; firing queues a signed delivery to each active,
  subscribed endpoint in the current team scope.
- **Signing**: `X-Kinetix-Signature: hmac_sha256(rawBody, secret)`, `X-Kinetix-Event`.
  Body = `{"event": "...", "data": {...}}`. Secret shown once (create/rotate).
- **SSRF** (`WebhookUrlGuard`): rejects non-HTTP(S) + private/loopback/link-local/
  reserved IPs (incl. `169.254.169.254`), at save time and before each delivery.
  `allow_private=true` permits local hosts.
- **Driver** (`WebhookDispatcher::usesWebhookServer()`): `auto` uses
  `spatie/laravel-webhook-server` when installed (its retries/backoff), else the
  native job. With spatie, deliveries are logged via a listener on its
  `WebhookCallSucceeded/Failed` events (correlated by `meta`), the signature uses
  spatie's `Signature` header, and retries/timeout come from spatie's config.
- **Retries** (native driver) via the queue (`tries`/backoff); every attempt
  logged. Prune with `kinetix:webhooks:prune`.
- Endpoints (gated `webhooks.manage`): `GET/POST {prefix}/webhooks`,
  `PUT/DELETE {prefix}/webhooks/{endpoint}`, `{endpoint}/rotate`, `{endpoint}/test`,
  `{endpoint}/logs`, `logs/{log}/redeliver`.

---

## Frontend Usage

```vue
<KinetixCan permission="webhooks.manage">
  <KinetixWebhookManager />
</KinetixCan>
```

Register/edit endpoints (events picked via `KinetixCheckbox`), rotate secret, send
a test, inspect logs. `useKinetixWebhooks()` for a custom UI.

## Testing tip

Point an endpoint at a request catcher like **webhookcatcher.com** (or
webhook.site) to inspect headers/signature/payload; for a local catcher set
`KINETIX_WEBHOOKS_ALLOW_PRIVATE=true`.
