# Developer Tokens

Kinetix Developer Tokens gives your users a self-service dashboard to mint, scope
and revoke **personal access tokens** for your API. It is a thin, opinionated
layer on top of [Laravel Sanctum](https://laravel.com/docs/sanctum): you declare
the **scopes** (abilities) a token may be granted, and Kinetix renders the UI,
validates the chosen scopes, and reveals the plaintext token exactly once.

Each authenticated user manages **only their own** tokens — there is no admin
ability, since tokens are personal credentials.

---

## Installation

Developer Tokens requires `laravel/sanctum`:

```bash
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
php artisan migrate
```

Add Sanctum's `HasApiTokens` trait to your authenticatable model:

```php
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
}
```

Then enable the feature:

```php
'tokens' => [
    'enabled' => env('KINETIX_TOKENS_ENABLED', true),

    // The abilities a token may be granted (key => label). Leave empty to
    // issue full-access ('*') tokens with no scope picker.
    'scopes'  => [
        'posts.read'  => 'Read posts',
        'posts.write' => 'Write posts',
    ],
    // The scopes the create form starts with ticked (keys outside
    // `scopes` are ignored).
    'default_scopes' => ['posts.read'],
],
```

---

## 1. Declaring scopes

Scopes are the abilities a token can carry — exactly Sanctum's
[token abilities](https://laravel.com/docs/sanctum#token-abilities). Declare them
in config (above) or, dynamically, from a service provider:

```php
use Happones\Kinetix\Tokens\KinetixTokens;

// Provider boot()
KinetixTokens::scopes([
    'posts.read'  => 'Read posts',
    'posts.write' => 'Write posts',
    'billing.read' => 'Read billing',
]);
```

When a scope catalog is declared, a token **must** be granted at least one of the
declared scopes, and any ability outside the catalog is rejected (422). When the
catalog is empty, tokens are issued with full access (`*`) and no scope picker is
shown.

Name the scopes a new token should start with — typically the read-only ones —
with `default_scopes` or at runtime:

```php
KinetixTokens::defaultScopes(['posts.read', 'billing.read']);
```

The create form opens with those ticked; the person can still untick them.

---

## 2. The dashboard component

Mount the drop-in manager wherever your users manage their credentials (for
example an "API" settings tab):

```vue
<script setup lang="ts">
import KinetixTokenManager from "@/components/kinetix/KinetixTokenManager.vue";
</script>

<template>
  <KinetixTokenManager />
</template>
```

<Screenshot name="token-manager" alt="API token manager" />

It lists the user's tokens (name, scopes, created, last-used, expiry — stacking
cleanly on mobile), provides a create form with
a scope picker, **reveals the plaintext token once** in a copy-able banner, and
revokes tokens. All strings are localized (`token_*` keys, en/es/fr/pt).

---

### Expiration

The create form includes an optional **expiration date** (shadcn calendar).
The token is persisted with Sanctum's native `expires_at` — the guard rejects
it automatically past that date (end of the chosen day) — and the list badges
each token with its expiry (red **Expired** once past). Leave it empty for a
non-expiring token. Server-side, `expires_at` must be a future date (422
otherwise).

## 3. Enforcing scopes on your API

Kinetix only issues the tokens — guarding your API routes is standard Sanctum.
Protect routes with the `auth:sanctum` guard and check abilities with the
`abilities` / `ability` middleware:

```php
Route::middleware(['auth:sanctum', 'ability:posts.write'])
    ->post('/api/posts', [PostController::class, 'store']);
```

Or check inline:

```php
if ($request->user()->tokenCan('posts.write')) {
    // ...
}
```

### Auditing what each token calls

Compose the `kinetix.api-log` middleware into the same group to log every
request — method, path, status, duration and the **token id/name** — and
inspect them with `<KinetixIntegrationLogs only="api" />`:

```php
Route::middleware(['auth:sanctum', 'kinetix.api-log'])
    ->prefix('api/v1')
    ->group(function () { /* … */ });
```

See [Integration Logs](/integration-logs) for configuration (opt-in bodies,
redaction, retention).

---

## 4. Tokens per team

In a multi-team app a token should act on ONE team — the one it was minted for —
not on whichever team its holder opened last. Kinetix supports two ways to tie a
token to a team; use either or both.

### The team column

Add a column to Sanctum's `personal_access_tokens` table and name it:

```php
Schema::table('personal_access_tokens', function (Blueprint $table) {
    $table->foreignId('team_id')->nullable()->index();
});
```

```php
'tokens' => ['team_column' => 'team_id'],
```

New tokens are stamped with the team being served; the list shows that team's
tokens (and the teamless ones minted before the column), and a token can only be
revoked from its own team. `php artisan kinetix:doctor` reports a configured
column the table does not have.

### The ability seal

When a column is not an option, seal the team into the token's abilities:

```php
'tokens' => [
    'team_ability'    => 'team:',          // seals `team:{teamKey}` on create
    'stamp_abilities' => ['integration'],  // added to every token on create
],
```

The list and revoke only reach the current team's tokens (plus unsealed ones).
The seal and the stamped abilities never reach the UI (`TokenData` hides them),
and the create form cannot request a seal of its own. Read the team back with
`KinetixTokens::teamOf($token)` (the column first, then the seal). Check the
stored abilities, not `tokenCan('team:7')`: a `*` token "can" every ability.

### Acting on the token's team

Put `kinetix.token-team` after the authentication middleware of your API routes:

```php
Route::middleware(['auth:sanctum', 'kinetix.token-team', 'kinetix.api-log'])
    ->prefix('api/v1')
    ->group(function () { /* … */ });
```

For a request authenticated by a stored token that names a team, the work done
while the request is handled acts on THAT team: `KinetixTeams`, `ScopedToTeam`,
route-model bindings (the middleware runs right after authentication, before
`SubstituteBindings`), billing and plan limits, `currentRouteKey()` and the
permission checks (the holder's loaded roles are re-read under that team), and a
streamed body. Rules:

- The holder must still be a member of the team (`403` when they left). Only
  tokens issued to team members belong behind this middleware: a token issued to
  a non-member (a device, field staff) gets `403`.
- A `{current_team}` / `{team}` segment in the URL must be the token's own team
  (compared by the team's route key, whether id, slug or uuid): another team's
  segment answers `403`, one the holder is not a member of answers `404`.
- Tokens without a team pass untouched, or get a `403` with
  `'tokens' => ['require_team' => true]`. `Sanctum::actingAs()` counts as a
  teamless token there. Session requests and apps with teams off are never
  affected.
- The API log (`kinetix.api-log`) is attributed to the token's team too.
- Order: list `kinetix.token-team` after the authentication middleware; the
  kernel's priority list then runs it before route-model binding. After
  binding it runs `kinetix.permissions.team` (spatie's team id), then
  `kinetix.entitled`, then spatie's role and permission middleware, so a plan
  or role check always sees the token's team (and the right spatie team)
  whatever the order you wrote. A `Route::bind('current_team', ...)` of your own
  is applied to the URL segment (it must return a team model, else `404`); a
  `{team:field}` binding field is not (the team's route key is compared).
- Callbacks that run after the response (`afterResponse`, `terminate()`,
  queued jobs) are NOT pinned: capture the team key and pass it explicitly
  (`fireFor($teamKey, ...)`, job payloads).

A catalog scope that starts with the `team_ability` prefix or equals a stamped
ability is refused on create (`422`), ignored when reading a token's team, and
reported as an error by `kinetix:doctor`. The doctor also counts tokens that
carry no seal once sealing is on.

### Guarding only the creation

`tokens.create_middleware` applies to the create route and nothing else:

```php
'create_middleware' => ['kinetix.entitled:api', 'can:webhooks.manage'],
```

Listing and revoking stay open, so a team that downgrades can still see and
revoke the tokens it already minted.

Apps that replaced `TokenController` to scope tokens per team can drop it and
configure `team_ability`, `stamp_abilities` and `create_middleware` instead.

---

## 5. The endpoints

While an admin is impersonating the user, creating or revoking tokens answers
`403` (the list still loads): an impersonating admin can't mint an API token
that would outlive the session. See [Impersonation](/impersonation).


The feature registers self-service routes under your Kinetix prefix
(team-aware when `kinetix.teams` is on):

| Method   | Route                       | Name                     |
| -------- | --------------------------- | ------------------------ |
| `GET`    | `{prefix}/tokens`           | `kinetix.tokens.index`   |
| `POST`   | `{prefix}/tokens`           | `kinetix.tokens.store`   |
| `DELETE` | `{prefix}/tokens/{token}`   | `kinetix.tokens.destroy` |

`index` returns the caller's tokens (never the plaintext value) plus the scope
catalog. `store` creates a token and returns `plainTextToken` once. `destroy`
revokes one of the caller's own tokens.
