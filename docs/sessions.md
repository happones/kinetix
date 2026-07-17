# Browser Sessions

Kinetix Browser Sessions gives users a self-service view of their **active
sessions across devices** — browser, platform, IP and last-active time — and a
one-click **log out other devices**. It's the modern, shadcn-styled take on
Jetstream's browser-sessions panel, with no extra user-agent dependency (Kinetix
ships a tiny built-in parser).

Each authenticated user manages **only their own** sessions.

---

## Installation

The session list reads Laravel's `sessions` table, so it needs the **database**
session driver:

```dotenv
SESSION_DRIVER=database
```

```bash
php artisan make:session-table   # if you don't have the sessions table yet
php artisan migrate
```

Then enable the feature:

```php
'sessions' => [
    'enabled' => env('KINETIX_SESSIONS_ENABLED', true),

    // Require the current password to confirm logging out other devices
    // (skipped automatically for users who have no password set).
    'require_password' => env('KINETIX_SESSIONS_REQUIRE_PASSWORD', true),
],
```

No migration is published — Kinetix reads your existing `sessions` table.

::: tip Other session drivers
With any non-database driver (`file`, `redis`, `cookie`, …) sessions aren't
stored per-row, so the list can't be shown. The component degrades gracefully to
a short notice; the rest of your app is unaffected.
:::

---

## The manager component

Mount the drop-in manager on a security / account settings page:

```vue
<script setup lang="ts">
import KinetixSessions from "@/components/kinetix/KinetixSessions.vue";
</script>

<template>
  <KinetixSessions />
</template>
```

<Screenshot name="sessions" alt="Browser sessions manager" />

It lists each session with a device icon (desktop / mobile / tablet), the
browser + platform, IP address and relative last-active time, badges the current
device, and offers **Log out other sessions** behind a password prompt (when the
user has a password). All strings are localized (`session*` keys, en/es/fr/pt).

---

## How "log out other sessions" works

On confirmation Kinetix verifies the current password (when required), then
**deletes every other session row** for the user from the `sessions` table,
keeping the current request's session. Those devices are signed out on their next
request. This mirrors Jetstream's behavior without requiring the
`AuthenticateSession` middleware.

---

## The endpoints

Registered under your Kinetix prefix (team-aware when `kinetix.teams` is on):

| Method   | Route                          | Name                            |
| -------- | ------------------------------ | ------------------------------- |
| `GET`    | `{prefix}/sessions`            | `kinetix.sessions.index`        |
| `DELETE` | `{prefix}/sessions/others`     | `kinetix.sessions.destroy-others` |

`index` returns the caller's sessions, a `databaseDriver` flag and
`requiresPassword`. `destroyOthers` validates the password (when required) and
removes all of the caller's other sessions.
