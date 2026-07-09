# Integration Logs

A SaaS back-office needs answers when an integration misbehaves: *what did we
send that webhook endpoint, what did it reply, and what is that API token
actually calling?* Kinetix ships both halves — an **API request logger**
(middleware + store) and a unified **viewer component** that also surfaces the
webhook delivery log.

<Screenshot name="integration-logs" alt="Integration logs — webhook deliveries tab" />

---

## 1. Webhook delivery logs

The [Webhooks module](/webhooks) already records every delivery attempt
(event, payload, response, status, attempt) in `kinetix_webhook_logs`. Two
feeds expose it, both gated by `webhooks.manage` and team-scoped:

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `{prefix}/webhooks/logs` | All endpoints — `?result=success\|failed`, `?search=` (event / endpoint name) |
| `GET` | `{prefix}/webhooks/{endpoint}/logs` | One endpoint's deliveries |
| `POST` | `{prefix}/webhooks/logs/{log}/redeliver` | Re-dispatch a delivery |

Each entry includes the **payload**, the **response body**, and the endpoint's
name/URL — everything the detail modal shows. Retention:
`kinetix:webhooks:prune` (config `kinetix.webhooks.retention_days`).

---

## 2. API request logs

Opt-in module for logging requests to your token-authenticated API:

```php
// config/kinetix.php
'api_logs' => [
    'enabled'           => true,
    'log_request_body'  => false,  // opt-in, redacted + size-capped
    'log_response_body' => false,
    'body_limit'        => 10240,  // bytes
    'retention_days'    => 30,
    'redact'            => ['password', 'secret', 'token', /* … */],
],
```

```bash
php artisan vendor:publish --tag=kinetix-api-logs-migrations
php artisan migrate
```

Attach the middleware to your API group — the row is written in `terminate()`
(after the response is sent), so logging adds **no request latency**:

```php
Route::middleware(['auth:sanctum', 'kinetix.api-log'])
    ->prefix('api/v1')
    ->group(function () { /* … */ });
```

Each row records the method, path, status, **duration (ms)**, ip, the Sanctum
**token id/name** (via `currentAccessToken()`), and — only when enabled — the
request body (sensitive keys replaced with `[redacted]`, oversized payloads
stored as a truncation marker) and the response body (truncated at
`body_limit`).

The feed (`GET {prefix}/api-logs`, filters `?result=` / `?search=`) is gated by
**`viewKinetixApiLogs`** — local-only by default, so define the gate in
production:

```php
Gate::define('viewKinetixApiLogs', fn ($user) => $user->isAdmin());
```

Keep the table bounded — schedule the prune:

```php
Schedule::command('kinetix:api-logs:prune')->daily();
```

---

## 3. The viewer component

```vue
<script setup lang="ts">
import KinetixIntegrationLogs from '@/components/kinetix/KinetixIntegrationLogs.vue';
</script>

<template>
    <!-- Both feeds, tabbed -->
    <KinetixIntegrationLogs />

    <!-- Or a single feed -->
    <KinetixIntegrationLogs only="api" />
</template>
```

- **Tabs**: Webhook deliveries · API requests (hide one with `only`).
- **Filters**: success/failed band + debounced search; paginated (15/page).
- **Detail modal**: pretty-printed payload/request body, the response, status,
  attempt/duration — and one-click **redeliver** for webhook entries.

Each feed enforces its own gate server-side (`webhooks.manage` /
`viewKinetixApiLogs`); mount the component behind the same check for a clean
denied state.
