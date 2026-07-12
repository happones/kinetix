---
name: kinetix-confidential
description: "Field-level encryption for Eloquent attributes — encrypted at rest, masked on read (••••6789), revealed for a short session window after password confirmation. Add ConfidentialCast to a model's casts(); mount <KinetixConfidentialUnlock> once. Activates when building masked/encrypted PII fields (national IDs, card numbers, etc.) with a reveal gate."
license: MIT
metadata:
  author: happones
---

# Kinetix Confidential Fields Development

## When to Apply

Activate this skill when:
- Storing sensitive PII (national IDs, card numbers, etc.) that must be
  encrypted at rest and masked in the UI by default.
- Building a "confirm your password to reveal" flow for sensitive data.
- The request mentions masking, unmasking, or a reveal/unlock gate for
  specific model fields.

## Documentation

For full details, reference `docs/confidential.md` (published at
https://happones.github.io/kinetix/confidential).

## Installation & Configuration

```php
'confidential' => [
    'enabled' => env('KINETIX_CONFIDENTIAL_ENABLED', false),

    // 'local' (zero-dependency, wraps keys via the app's own APP_KEY) or a
    // class implementing Happones\Kinetix\Confidential\KeyManagers\KeyManager.
    'key_manager' => env('KINETIX_CONFIDENTIAL_KEY_MANAGER', 'local'),

    'reveal_ttl_minutes'    => env('KINETIX_CONFIDENTIAL_REVEAL_TTL', 5),
    'require_password'      => env('KINETIX_CONFIDENTIAL_REQUIRE_PASSWORD', true),
    'mask_visible'          => env('KINETIX_CONFIDENTIAL_MASK_VISIBLE', 4),
    'key_cache_ttl_minutes' => env('KINETIX_CONFIDENTIAL_KEY_CACHE_TTL', 10),
],
```

```bash
php artisan migrate                          # creates kinetix_confidential_keys
php artisan kinetix:confidential:rotate-key  # generate the first encryption key (required once)
```

## Key design: masking is enforced in the cast, not the UI

`ConfidentialCast` decrypts and masks/reveals the attribute wherever it's
read — Table, Infolist, Blade, API Resource, tinker. A `->confidential()`
flag on `TextColumn`/`TextEntry` is **cosmetic only** (adds a padlock icon);
the value is masked with or without it.

## Add the cast to a model

```php
use Happones\Kinetix\Confidential\Casts\ConfidentialCast;
use Happones\Kinetix\Confidential\Concerns\HasConfidentialAttributes;

class Customer extends Model
{
    use HasConfidentialAttributes;

    protected function casts(): array
    {
        return [
            'national_id' => ConfidentialCast::class,
            'card_number' => ConfidentialCast::class.':4,head', // show first 4, not last 4
        ];
    }
}
```

No migration needed on the host table beyond a `TEXT`/`LONGTEXT` column.
`HasConfidentialAttributes` doesn't touch `casts()` (this codebase never
composes `casts()` via traits) — it only adds a static
`Customer::confidentialColumns()` introspection helper.

Adopting a column that already has real plaintext data:

```bash
php artisan kinetix:confidential:encrypt-existing "App\\Models\\Customer" --column=national_id
```

## Frontend

```vue
<KinetixConfidentialUnlock />
```

Zero props, mount once in the layout header. Padlock button → password
dialog (`require_password`) → opens the reveal window for
`reveal_ttl_minutes` with a live countdown + "Lock now".

## Performance: one key, not one per row

A KMS-backed key manager is called **at most once per `key_cache_ttl_minutes`
window**, not once per confidential value rendered — `ConfidentialManager`
caches the unwrapped Data Encryption Key. Rotate keys any time with
`kinetix:confidential:rotate-key`; older data stays decryptable (each
envelope embeds the `key_id` it was encrypted under).

## Limitations

- String attributes only.
- Don't mark a confidential column `->searchable()`/`->sortable()` — a fresh
  random IV means identical plaintext never produces identical ciphertext.
- Masking doesn't preserve separators (`123-45-6789` → `•••••••6789`).
- Queued jobs (e.g. a Reports Center export) have no active session, so
  confidential columns are masked by default there too — use
  `Confidential::revealed(fn () => ...)` only from an explicitly-authorized,
  synchronous backend action, never ambiently in a queue worker.
- One global keyring in v1, not per-team.
