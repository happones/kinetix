---
name: kinetix-sessions
description: "Browser sessions / device management: list the user's active sessions (device, browser, IP, last active) and log out other devices, password-gated. Reads Laravel's sessions table (SESSION_DRIVER=database). Activates when adding device management or 'log out other sessions'."
license: MIT
metadata:
  author: happones
---

# Kinetix Browser Sessions Development

## When to Apply

Activate this skill when:
- Adding a "browser sessions" / "active devices" list to account settings.
- Implementing "log out other browser sessions".

The modern shadcn take on Jetstream's browser sessions, with a built-in
user-agent parser (no `jenssegers/agent`).

## Documentation

For full details, reference `docs/sessions.md` (published at https://happones.github.io/kinetix/sessions).

## Installation & Configuration

Reads Laravel's `sessions` table → requires the database session driver:

```dotenv
SESSION_DRIVER=database
```

```bash
php artisan make:session-table   # if needed
php artisan migrate
```

```php
'sessions' => [
    'enabled'          => env('KINETIX_SESSIONS_ENABLED', false),
    'require_password' => env('KINETIX_SESSIONS_REQUIRE_PASSWORD', true), // skipped for passwordless users
],
```

No migration is published — it reads the existing `sessions` table. With a
non-database driver the list is unavailable (component shows a notice).

---

## Backend

- **`BrowserSessionManager`**: `usesDatabaseDriver()`, `for($user, $request)`
  (parses each row's user agent, marks the current device first), and
  `logoutOthers($user, $request)` (deletes the user's other session rows, keeps
  the current request's). `BrowserSessionData` DTO carries
  `id, ipAddress, browser, platform, device, isCurrentDevice, lastActive`.
- **`SessionController`** (self-service, no admin ability):
  `GET {prefix}/sessions` (sessions + `databaseDriver` + `requiresPassword`),
  `DELETE {prefix}/sessions/others` (validates `current_password` only when
  configured **and** the user has a password). Team-aware prefix.
- `Sessions\UserAgentParser::parse($ua)` → `{browser, platform, device}` — a tiny
  dependency-free detector for mainstream browsers/OSes.

---

## Frontend

```vue
<KinetixSessions />
```

Lists sessions with device icons (desktop/mobile/tablet), browser + platform, IP,
relative last-active, a "this device" badge, and **Log out other sessions** with
an inline password prompt (when required). `useKinetixSessions()` →
`{ sessions, databaseDriver, requiresPassword, loading, load, logoutOthers }`.
i18n `session*` (en/es/fr/pt).
