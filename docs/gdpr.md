# GDPR self-service

Kinetix GDPR gives users two self-service privacy actions:

- **Download my data** — a queued export of their personal data (delivered as a
  notification with a download link), and
- **Delete my account** — password-gated deletion that either **anonymizes** the
  configured PII columns or **hard-deletes** the record.

Each user acts only on their own account — there is no admin ability.

---

## Installation

No migration — GDPR reuses the exports download route and notifications.

```php
'gdpr' => [
    'enabled'          => env('KINETIX_GDPR_ENABLED', true),
    // 'anonymize' scrubs the columns below; 'delete' removes the record.
    'deletion'         => env('KINETIX_GDPR_DELETION', 'anonymize'),
    // Require the current password to confirm deletion.
    'require_password' => env('KINETIX_GDPR_REQUIRE_PASSWORD', true),
    // Column => replacement value (or closure) applied when anonymizing.
    'anonymize'        => [
        'name'  => 'Deleted user',
        'email' => null,
    ],
    // Where the SPA navigates after deletion.
    'redirect'         => env('KINETIX_GDPR_REDIRECT', '/'),
],
```

Exports run on the queue — make sure a worker is running.

---

## 1. Declaring the data export

Register the sections that make up a user's data export in a service provider.
Each resolver receives the authenticated user and returns anything JSON-encodable
(arrays, `Arrayable`, Eloquent models/collections):

```php
use Happones\Kinetix\Gdpr\KinetixGdpr;

KinetixGdpr::export('profile', fn ($user) => $user->only(['name', 'email', 'created_at']));
KinetixGdpr::export('orders', fn ($user) => $user->orders);
KinetixGdpr::export('addresses', fn ($user) => $user->addresses);
```

When the user requests an export, Kinetix builds a single JSON document of every
section, stores it on the Kinetix disk, and notifies the user with a one-time
download link.

---

## 2. Customizing deletion

By default deletion follows `kinetix.gdpr.deletion`:

- `anonymize` — sets each column in `kinetix.gdpr.anonymize` to its replacement
  (a value or a `fn ($user) => …` closure) and saves. Soft-deletable models are
  also soft-deleted so they drop out of normal queries.
- `delete` — hard-deletes the record.

For full control (cascade cleanup, billing cancellation, etc.) provide your own
handler — it takes over completely:

```php
KinetixGdpr::deleteUsing(function ($user) {
    $user->subscriptions->each->cancelNow();
    $user->forceDelete();
});
```

---

## 3. The panel component

Mount the drop-in panel on a privacy / account settings page:

```vue
<script setup lang="ts">
import KinetixGdprPanel from "@/components/KinetixGdprPanel.vue";
</script>

<template>
  <KinetixGdprPanel :require-password="true" />
</template>
```

<Screenshot name="gdpr-panel" alt="GDPR self-service panel" />

It renders the "Download your data" action and a destructive "Delete account"
action behind a confirmation dialog (with a password field when
`require_password` is on). On deletion it logs the user out and navigates to the
configured `redirect`. `useKinetixGdpr()` exposes `exportData()` and
`deleteAccount(password?)` for a custom UI. All strings are localized
(`gdpr_*`, en/es/fr/pt).

---

## Endpoints

| Method | Route                    | Name                  |
| ------ | ------------------------ | --------------------- |
| `POST` | `{prefix}/gdpr/export`   | `kinetix.gdpr.export` |
| `POST` | `{prefix}/gdpr/delete`   | `kinetix.gdpr.delete` |

`export` queues the data dump; `delete` validates the password (when required),
purges the account, and ends the session. Both act on the authenticated user.
