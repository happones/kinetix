---
name: kinetix-tokens
description: "Self-service developer tokens: users mint/scope/revoke Sanctum personal access tokens from a drop-in dashboard; you declare the grantable scopes. Activates when declaring token scopes, mounting the token manager, or enforcing abilities on an API."
license: MIT
metadata:
  author: happones
---

# Kinetix Developer Tokens Development

## When to Apply

Activate this skill when:
- Declaring grantable scopes (`KinetixTokens::scopes`) or configuring `kinetix.tokens`.
- Mounting `<KinetixTokenManager>` / using `useKinetixTokens`.
- Enforcing token abilities on API routes (`auth:sanctum`, `ability:`).
- Logging/auditing token-authenticated API requests (`kinetix.api-log` middleware, `<KinetixIntegrationLogs>`).

## Documentation

For full details, reference `docs/tokens.md` (published at https://happones.github.io/kinetix/tokens).

## Requirement

Requires `laravel/sanctum`, and the authenticatable model must use
`Laravel\Sanctum\HasApiTokens`. Without it, the endpoints abort 500.

```bash
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
php artisan migrate
```

## Configuration

```php
'tokens' => [
    'enabled' => env('KINETIX_TOKENS_ENABLED', false),
    // key => label; empty = full-access ('*') tokens, no scope picker.
    'scopes'  => ['posts.read' => 'Read posts', 'posts.write' => 'Write posts'],
],
```

---

## Backend Usage

```php
use Happones\Kinetix\Tokens\KinetixTokens;

KinetixTokens::scopes(['posts.read' => 'Read posts']); // provider boot (merges with config)
```

- Scopes are Sanctum **token abilities**. When a catalog is declared, `store`
  requires ≥1 declared scope and rejects abilities outside it (422). Empty
  catalog → tokens default to `['*']`.
- **Self-service**: each user manages only their own tokens (no admin ability).
  `index`/`store`/`destroy` operate on `$request->user()->tokens()`.
- **Reveal once**: `store` returns `plainTextToken` exactly once; it is never
  persisted readable. `index` never includes it.
- **Expiration**: `store` accepts optional `expires_at` (future date, 422 otherwise) → `createToken($name, $abilities, $expiresAt)` persisted at END of the chosen day; Sanctum's guard rejects expired tokens automatically. The manager form has a `KinetixDatePicker` (min = tomorrow) and the list badges expiry (`token_expires`/`token_expired`).
- Endpoints (team-aware prefix): `GET/POST {prefix}/tokens`,
  `DELETE {prefix}/tokens/{token}`.
- Enforce on your API with standard Sanctum:
  `Route::middleware(['auth:sanctum', 'ability:posts.write'])` or
  `$request->user()->tokenCan('posts.write')`.

## API request logging (audit what each token calls)

Compose the `kinetix.api-log` middleware into the same group to log every
request — method, path, status, duration (ms), ip and the Sanctum **token
id/name** (`currentAccessToken()`), so you can answer "what is this
integration's token actually doing?":

```php
Route::middleware(['auth:sanctum', 'kinetix.api-log'])
    ->prefix('api/v1')
    ->group(function () { /* … */ });
```

- Opt-in via `kinetix.api_logs.enabled`; publish `--tag=kinetix-api-logs-migrations` and migrate.
- The row is written in `terminate()` (zero request latency) and logging never throws into the response path.
- Request/response bodies are opt-in (`log_request_body`/`log_response_body`), size-capped (`body_limit`) and sensitive keys (`redact`) become `[redacted]`.
- View per-token activity with `<KinetixIntegrationLogs only="api" />` (feed `GET {prefix}/api-logs`, gate `viewKinetixApiLogs` — local-only default, define it in production). Retention: schedule `kinetix:api-logs:prune`.
- Full guide: `docs/integration-logs.md`.

---

## Frontend Usage

```vue
<KinetixTokenManager />
```

Lists tokens (name, scopes, last-used), create form with a `KinetixCheckbox`
scope picker, copy-able reveal-once banner, revoke. `useKinetixTokens()` for a
custom UI. All strings localized (`token_*`, en/es/fr/pt).

## UUID / ULID Host Models

The published migration types `user_id` and `team_id` (kinetix_api_logs) as `unsignedBigInteger`. If the
referenced model uses UUIDs or ULIDs, publish
`--tag=kinetix-api-logs-migrations` and retype those columns
(`$table->uuid(…)` / `$table->ulid(…)`) BEFORE `php artisan migrate` —
type each column after the model it points to. Full recipe: the
`kinetix-boost` skill, section "UUID / ULID Host Models".
