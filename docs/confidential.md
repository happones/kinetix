# Confidential Fields

Field-level encryption for sensitive Eloquent attributes (national IDs, card
numbers, anything you'd otherwise mask): encrypted at rest, masked on read
(`••••6789`) by default, and revealed for a short window once the current
user confirms their password — a session-scoped "unlock," not a permanent
setting. Add one cast to a model's `casts()` and it works everywhere the
attribute is read: Tables, Infolists, Blade, API Resources, even
`php artisan tinker`.

<Screenshot name="confidential-mask" alt="A masked confidential field next to its unlocked value" />

::: tip Where the protection actually lives
Masking is enforced **inside the Eloquent cast itself**, not in any UI
component. A `->confidential()` flag on a `TextColumn`/`TextEntry` only adds
a padlock icon — remove it and the value is still masked, because the cast
doesn't know or care which UI is reading it. One enforcement point protects
every surface automatically.
:::

---

## Installation

```php
'confidential' => [
    'enabled' => env('KINETIX_CONFIDENTIAL_ENABLED', false),

    // 'local' (zero-dependency, wraps keys via the app's own APP_KEY) or a
    // class implementing Happones\Kinetix\Confidential\KeyManagers\KeyManager.
    'key_manager' => env('KINETIX_CONFIDENTIAL_KEY_MANAGER', 'local'),

    'reveal_ttl_minutes' => env('KINETIX_CONFIDENTIAL_REVEAL_TTL', 5),
    'require_password'   => env('KINETIX_CONFIDENTIAL_REQUIRE_PASSWORD', true),
    'mask_visible'        => env('KINETIX_CONFIDENTIAL_MASK_VISIBLE', 4),
    'key_cache_ttl_minutes' => env('KINETIX_CONFIDENTIAL_KEY_CACHE_TTL', 10),
],
```

Publish and run the migration (creates `kinetix_confidential_keys`, the keyring
— not your data):

```bash
php artisan vendor:publish --tag=kinetix-confidential-migrations
php artisan migrate
```

Generate the first encryption key — required once before anything can be
encrypted:

```bash
php artisan kinetix:confidential:rotate-key
```

Running it again **rotates**: the previous key is retired (kept, so
historical data stays decryptable) and a fresh key becomes current for new
writes. It also clears every cached key row, and any unwrapped key a version
before 0.274 left in the cache (`kinetix-confidential-dek:*`).

Rotation doesn't touch existing rows: they stay encrypted under the key that
wrote them. To move them to the new current key (say, because the old one may
be exposed), read each value revealed and assign it back; the cast encrypts it
again with a fresh IV under the current key, so the column is dirty and saved:

```php
use App\Models\Customer;
use Happones\Kinetix\Confidential\Confidential;

Confidential::revealed(fn () => Customer::query()->cursor()->each(function (Customer $customer): void {
    foreach (Customer::confidentialColumns() as $column) {
        $customer->{$column} = $customer->{$column};
    }

    $customer->save();
}));
```

Run it from a console command, not a request. It goes through Eloquent, so
model events and observers fire; use `saveQuietly()` if that matters. Keep
the old key row: until a row is rewritten, it still decrypts with it.

::: warning Upgrading from 0.273 or earlier
Earlier versions cached the **unwrapped** key in the app cache. Run
`php artisan kinetix:confidential:rotate-key` once after upgrading: it purges
those entries and moves new writes to a fresh key. If the cache is a database
or a store others can read, treat the old key as exposed and re-encrypt the
existing rows with the recipe above.

The `database` cache store doesn't garbage-collect expired rows, so an old
entry stays in the `cache` table until it is overwritten or forgotten.
`rotate-key` forgets the entries of every key id it knows; to be sure nothing
is left, delete them by key (the store prefixes every key, hence the leading
`%`):

```php
DB::table(config('cache.stores.database.table', 'cache'))
    ->where('key', 'like', '%kinetix-confidential-dek:%')
    ->delete();
```
:::

---

## 1. Add the cast to a model

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
            'card_number' => ConfidentialCast::class.':4,head', // show first 4 instead of last 4
        ];
    }
}
```

That's it — `$customer->national_id` now returns `••••••6789` unless the
current session has unlocked confidential fields, in which case it returns
the real value. No migration is needed on `Customer`'s own table beyond
making sure the column is `TEXT`/`LONGTEXT` (the encrypted envelope is
larger than plaintext).

`HasConfidentialAttributes` is a thin trait — it doesn't touch `casts()` for
you (this codebase never composes `casts()` via traits). It adds one static
helper, `Customer::confidentialColumns()`, useful for your own audit tooling.

**Colon arguments**: `ConfidentialCast::class.':<visible>,<head|tail>'` — how
many real characters to show, and from which end. Omit them to use the
config default (`mask_visible`, tail).

### Edit forms withhold confidential fields

A Kinetix `Form` never sends a confidential attribute to the browser, masked
or not, and not even while unlocked: on an edit the field renders empty. Left
empty (or unchanged), saving keeps the stored value; typing a new value
replaces it. A dotted field (`owner.national_id`) is withheld the same way.
This is the same rule the form applies to `$hidden` attributes. Infolists,
tables and exports still show the mask (or the value, when unlocked).

As a second guard, the cast never stores a mask: assigning exactly the mask of
the stored value (`$customer->national_id = $customer->national_id` while
locked) keeps the stored ciphertext.

::: warning Upgrading from 0.273 or earlier
Before 0.274, saving an edit form untouched while locked stored the mask
(`••••••6789`) encrypted in place of the real value. To find rows hit by it,
read the column unlocked and look for values that contain `•`:

```php
Confidential::revealed(fn () => Customer::query()->cursor()
    ->filter(fn (Customer $customer) => str_contains((string) $customer->national_id, '•'))
    ->pluck('id'));
```

Their real values are gone from the database; restore them from a backup or
from the source system.
:::

### Adopting an already-populated column

If the column already has real plaintext data before you add the cast,
migrate it in place:

```bash
php artisan kinetix:confidential:encrypt-existing "App\\Models\\Customer" --column=national_id
```

Old rows read fine even before running this (a stored value that isn't valid
encrypted-envelope JSON is treated as legacy plaintext and just gets masked
as-is) — the command is what actually encrypts it at rest.

---

## 2. Mount the unlock widget

```vue
<script setup lang="ts">
import KinetixConfidentialUnlock from '@/components/kinetix/KinetixConfidentialUnlock.vue';
</script>

<template>
    <KinetixConfidentialUnlock />
</template>
```

Zero props — mount once in your header/layout. It shows a padlock button;
clicking it prompts for the current password (`require_password`, on by
default) and opens the reveal window for `reveal_ttl_minutes`. While
unlocked it shows a live countdown and a "Lock now" action. A masked
Table/Infolist cell built with `->confidential()` shows its own small
padlock that opens the same dialog.

<Screenshot name="confidential-unlock" alt="The confidential-fields unlock dialog, prompting for the current password" />

---

## 3. Performance — why one key, not one per row

A naive "one Data Encryption Key (DEK) per row" design would mean a
KMS-backed key manager gets called **once per confidential value on every
row of every list view** — a real bottleneck (and, for a cloud KMS, a real
cost/rate-limit problem) at any scale. Instead:

- There's **one "current" DEK at a time**, generated by
  `kinetix:confidential:rotate-key` and stored (wrapped, never in plaintext)
  in `kinetix_confidential_keys`.
- The unwrapped key is kept **in the memory of the PHP process only**, for
  `key_cache_ttl_minutes` — so a KMS round trip happens at most once per
  window and process (each PHP-FPM request, each queue worker, each Octane
  worker), no matter how many rows/fields render in between. The raw key is
  never written to the cache: the cache is shared, and is often a database
  table. The cache only holds each key row's id and **wrapped** key
  (`kinetix-confidential:v2:*`), which saves the keyring query.
  `app(ConfidentialManager::class)->flushKeys()` drops the in-memory keys of
  a long-lived worker on demand.
- Every encrypted value still gets its own random IV, and embeds which
  `key_id` encrypted it — so rotating keys never breaks decrypting older
  data.
- Per-value work beyond that is a single `aes-256-gcm` encrypt/decrypt call
  — sub-millisecond, negligible at any realistic row count.

---

## 4. Using a real KMS instead of the local driver

Kinetix ships one concrete driver, `LocalKeyManager` (wraps the DEK with
your app's own `APP_KEY`, zero network calls, zero extra dependencies). For
AWS KMS, GCP KMS, HashiCorp Vault Transit, etc., implement the two-method
interface yourself and point config at your class — Kinetix doesn't bundle
any cloud SDK:

```php
use Happones\Kinetix\Confidential\KeyManagers\KeyManager;

class AwsKmsKeyManager implements KeyManager
{
    public function generateDataKey(): array
    {
        $result = app('aws')->createClient('kms')->generateDataKey([
            'KeyId'   => config('services.kms.key_id'),
            'KeySpec' => 'AES_256',
        ]);

        return [
            'plaintext' => $result['Plaintext'],
            'wrapped'   => base64_encode($result['CiphertextBlob']),
        ];
    }

    public function unwrap(string $wrapped): string
    {
        $result = app('aws')->createClient('kms')->decrypt([
            'CiphertextBlob' => base64_decode($wrapped),
        ]);

        return $result['Plaintext'];
    }
}
```

```php
// config/kinetix.php
'key_manager' => \App\Services\AwsKmsKeyManager::class,
```

---

## 5. Limitations

- **Not searchable/sortable.** Ciphertext is unique per row even for
  identical plaintext (a fresh random IV every time), so don't mark a
  confidential `TextColumn` `->searchable()`/`->sortable()` — the DB can't
  meaningfully compare encrypted bytes. A blind-index/HMAC companion column
  is the standard way to add search later; not built here.
- **String attributes only.** `ConfidentialCast` targets text columns.
- **Masking doesn't preserve separators.** `123-45-6789` masks to
  `•••••••6789`, not `•••-••-6789` — a v1 simplification.
- **Queued jobs are always masked.** A background job (e.g. a Reports
  Center export) has no active HTTP session, so `Confidential::isUnlocked()`
  is `false` there by construction — bulk exports of confidential columns
  are masked by default. For a genuinely authorized backend export, wrap the
  read in `Confidential::revealed(fn () => ...)` from your *own*,
  synchronous, re-authenticated code path — never ambiently in a queue
  worker.
- **One global keyring in v1** — not per-team. Kinetix already has
  first-class team scoping elsewhere (`kinetix.activity`, `kinetix.settings`);
  a per-team keyring is a natural future extension, not built now.
