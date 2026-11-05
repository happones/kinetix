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

Mask rule (`Confidential::mask()`, multibyte): shows `min(visible, floor(len/2))`
real characters, and none when `len <= visible` (`1234` → `••••`, `12345` → `•••45`).
The cast's write-back guard compares against `mask()`, so keep them in step.

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
`reveal_ttl_minutes` with a live countdown + "Lock now". It's a lazy shell:
disabled, it renders and loads nothing; enabled, an icon-button-sized
placeholder holds the spot while the widget loads.

## Performance: one key, not one per row

A KMS-backed key manager is called **at most once per `key_cache_ttl_minutes`
window and process**, not once per confidential value rendered —
`ConfidentialManager` keeps the unwrapped Data Encryption Key in an instance
memo (process memory). **Never write a raw key to `Cache`** (it is shared and
often a DB table): the cache holds only scalar key rows
(`{key_id, wrapped_key}` under `kinetix-confidential:v2:current` /
`kinetix-confidential:v2:key:{id}`). `flushKeys()` drops the memo. Rotate
keys any time with `kinetix:confidential:rotate-key`, which also purges the
v2 rows and the legacy `kinetix-confidential-dek:{id}` /
`kinetix-confidential-key:{id}` entries for every key id; older data stays
decryptable (each envelope embeds the `key_id` it was encrypted under).

## Forms withhold confidential attributes (REQUIRED)

`Form` treats a confidential attribute (`ConfidentialCast::appliesTo($model,
$attribute)`) like a `$hidden` one: never hydrated into an edit form (masked
or not, even unlocked), dotted names included, and a blank or unchanged
submission keeps the stored value (`Form::withholds()` /
`keepsStoredValue()`). Never send a mask to a form: saving it back destroyed
the real value before 0.274. Defence in depth: `ConfidentialCast::set()`
keeps the stored ciphertext when the incoming value is exactly the mask of
the stored one (`$m->ssn = $m->ssn` while locked).

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
