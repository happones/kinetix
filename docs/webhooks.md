# Webhooks

Kinetix Webhooks lets your customers subscribe their own endpoints to platform
events. You declare the subscribable events and `fire()` them; Kinetix delivers a
**signed** (HMAC), **queued**, **retried** and **logged** request to every active
subscriber — and validates customer URLs against **SSRF**.

---

## Installation

```bash
php artisan vendor:publish --tag=kinetix-webhooks-migrations
php artisan migrate
```

```php
'webhooks' => [
    'enabled'        => env('KINETIX_WEBHOOKS_ENABLED', false),
    // auto = spatie/laravel-webhook-server when installed, else the native job.
    'driver'         => env('KINETIX_WEBHOOKS_DRIVER', 'auto'),
    'teams'          => env('KINETIX_WEBHOOKS_TEAMS', false),
    // Permit private/loopback URLs — dev/testing only (SSRF risk in production).
    'allow_private'  => env('KINETIX_WEBHOOKS_ALLOW_PRIVATE', false),
    'timeout'        => env('KINETIX_WEBHOOKS_TIMEOUT', 10),
    'tries'          => env('KINETIX_WEBHOOKS_TRIES', 3),
    'retention_days' => env('KINETIX_WEBHOOKS_RETENTION_DAYS', 30),
],
```

Deliveries run on the queue — make sure a worker is running.

---

## 1. Declaring & firing events

Declare the subscribable events in a service provider, then fire them from your
domain code:

```php
use Happones\Kinetix\Webhooks\KinetixWebhooks;

// Provider boot()
KinetixWebhooks::events([
    'order.created' => 'Order created',
    'order.shipped' => 'Order shipped',
]);

// Anywhere in your app
KinetixWebhooks::fire('order.created', ['id' => $order->id, 'total' => $order->total]);
```

Only **registered** events can be subscribed to or fired. Firing queues a signed
delivery to each active endpoint subscribed to that event (in the current team
scope).

---

## 2. The customer dashboard

Mount `<KinetixWebhookManager>` behind the `webhooks.manage` ability — customers
register endpoints, pick events, rotate the secret, send a test event and inspect
delivery logs:

```vue
<script setup lang="ts">
import KinetixCan from '@/components/kinetix/KinetixCan.vue'
import KinetixWebhookManager from '@/components/kinetix/KinetixWebhookManager.vue'
</script>

<template>
  <KinetixCan permission="webhooks.manage">
    <KinetixWebhookManager />
  </KinetixCan>
</template>
```

<Screenshot name="webhook-manager" alt="Webhook management dashboard" />

The **signing secret is shown exactly once** (on create and rotate) and never
returned again.

---

## 3. Verifying signatures (customer side)

Each delivery carries `X-Kinetix-Event` and an `X-Kinetix-Signature` —
`hash_hmac('sha256', rawBody, secret)`. The receiver verifies it:

```php
$expected = hash_hmac('sha256', $request->getContent(), $endpointSecret);

abort_unless(hash_equals($expected, $request->header('X-Kinetix-Signature')), 403);
```

The body is `{"event": "...", "data": { ... }}`.

---

## 4. Security & reliability

- **SSRF protection**: customer URLs are validated (`WebhookUrlGuard`) at save time
  **and** again before each delivery — non-HTTP(S) schemes and hosts resolving to
  private / loopback / link-local / reserved IPs (incl. cloud metadata
  `169.254.169.254`) are rejected. Set `allow_private = true` only locally.
- **Retries**: failed deliveries (non-2xx or transport error) retry on the queue
  with backoff (`tries`, default 3). Every attempt is logged.
- **Retention**: schedule `kinetix:webhooks:prune` so `kinetix_webhook_logs` stays
  bounded:

  ```php
  Schedule::command('kinetix:webhooks:prune')->daily();
  ```

---

## 5. Testing your webhooks

Point an endpoint at a request-catching service to watch deliveries land in real
time — [**webhookcatcher.com**](https://webhookcatcher.com) gives you a throwaway
URL and shows the headers, signature and payload of every request, which is ideal
for verifying your signature check. ([webhook.site](https://webhook.site) is a
common alternative.)

Create an endpoint with that URL, hit **Test** in the dashboard (or call
`KinetixWebhooks::fire(...)`), and inspect the captured request. For a **local**
catcher, set `KINETIX_WEBHOOKS_ALLOW_PRIVATE=true` so the SSRF guard permits it.

---

## 6. Endpoints

Team-aware, under the Kinetix route prefix, gated by `webhooks.manage`:

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `{prefix}/webhooks` | List endpoints + available events |
| `POST` | `{prefix}/webhooks` | Create (returns the secret once) |
| `PUT` | `{prefix}/webhooks/{endpoint}` | Update |
| `DELETE` | `{prefix}/webhooks/{endpoint}` | Delete |
| `POST` | `{prefix}/webhooks/{endpoint}/rotate` | New signing secret |
| `POST` | `{prefix}/webhooks/{endpoint}/test` | Queue a test delivery |
| `GET` | `{prefix}/webhooks/{endpoint}/logs` | Delivery logs |
| `POST` | `{prefix}/webhooks/logs/{log}/redeliver` | Re-queue a delivery |

---

## 7. Delivery driver

With `driver = auto` (default), Kinetix delivers through
[`spatie/laravel-webhook-server`](https://github.com/spatie/laravel-webhook-server)
when it's installed — inheriting its tuned retries/backoff — and falls back to the
native queued job otherwise. Force either with `driver = 'spatie'` / `'native'`.

Either way the **dashboard delivery logs are populated** and the **SSRF guard runs
before every delivery**. Two driver-specific differences to know:

- **Signature header**: the native driver sends `X-Kinetix-Signature`; the spatie
  driver uses spatie's `Signature` header (configurable via
  `config('webhook-server.signature_header_name')`). Both are HMAC-SHA256 of the
  raw body — verify against whichever your driver sends.
- **Retries/timeout**: with the spatie driver these come from spatie's own config
  (`webhook-server.tries` / `timeout_in_seconds`), not `kinetix.webhooks.*`.

Kinetix logs spatie's per-attempt outcomes by listening to its
`WebhookCallSucceeded`/`WebhookCallFailed` events (correlated by a `meta` tag), so
your dashboard stays consistent across drivers.
