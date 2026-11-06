<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * A secret encrypted at rest (with the app key) that still reads values
 * stored before it was encrypted.
 *
 * Reading: an encrypted payload is decrypted — and a payload that won't
 * decrypt THROWS (a key problem must not turn into signing with garbage);
 * anything else is a legacy plaintext value and is returned as it is.
 *
 * Writing: encrypted only when the column can hold the ciphertext (TEXT, or
 * a string column of at least 512 characters; SQLite always). On a column
 * still too narrow it is written as plaintext, so an app that hasn't
 * migrated yet keeps working instead of failing every write; `kinetix:doctor`
 * reports it.
 *
 * @internal
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class EncryptedWithPlaintextFallback implements CastsAttributes
{
    /** The shortest string column a ciphertext is written into. */
    public const MIN_LENGTH = 512;

    /**
     * `connection|table|column` => whether it holds ciphertext.
     *
     * @var array<string, bool>
     */
    private static array $capacity = [];

    /**
     * @param array<string, mixed> $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = (string) $value;

        return self::isEncryptedPayload($raw) ? Crypt::decryptString($raw) : $raw;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;

        if (! self::columnHoldsCiphertext($model->getConnectionName(), $model->getTable(), $key)) {
            return $value;
        }

        return Crypt::encryptString($value);
    }

    /**
     * Whether `$raw` is a Laravel encrypter payload: base64 of a JSON object
     * with string `iv`, `value` and `mac` (empty for AEAD ciphers), and an
     * optional string `tag`.
     */
    public static function isEncryptedPayload(string $raw): bool
    {
        if ($raw === '' || ! str_starts_with($raw, 'ey')) {
            return false;
        }

        $json = base64_decode($raw, true);

        if ($json === false) {
            return false;
        }

        $payload = json_decode($json, true);

        if (! is_array($payload)) {
            return false;
        }

        foreach (['iv', 'value', 'mac'] as $field) {
            if (! isset($payload[$field]) || ! is_string($payload[$field])) {
                return false;
            }
        }

        if (array_key_exists('tag', $payload) && ! is_string($payload['tag']) && $payload['tag'] !== null) {
            return false;
        }

        return $payload['iv'] !== '' && $payload['value'] !== '';
    }

    /**
     * Whether `$column` can store a ciphertext (about 200–300 characters for
     * a short secret): SQLite (no length enforced), a TEXT column, or a
     * string column of at least {@see MIN_LENGTH}. A missing table or column,
     * or a failing lookup, answers false: plaintext is written rather than a
     * value the database would refuse. Memoized per process ({@see flush()}).
     */
    public static function columnHoldsCiphertext(?string $connection, string $table, string $column): bool
    {
        $key = ($connection ?? '').'|'.$table.'|'.$column;

        return self::$capacity[$key] ??= self::lookUp($connection, $table, $column);
    }

    /** Forget the memoized column capacities (after a migration, in tests). */
    public static function flush(): void
    {
        self::$capacity = [];
    }

    private static function lookUp(?string $connection, string $table, string $column): bool
    {
        try {
            if (DB::connection($connection)->getDriverName() === 'sqlite') {
                return Schema::connection($connection)->hasColumn($table, $column);
            }

            foreach (Schema::connection($connection)->getColumns($table) as $definition) {
                if (strcasecmp($definition['name'], $column) !== 0) {
                    continue;
                }

                return self::fits($definition['type_name'], $definition['type']);
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    /**
     * Whether a column type (its name and full definition, as
     * `Schema::getColumns()` reports them) can hold a ciphertext.
     */
    public static function fits(string $typeName, string $type): bool
    {
        $typeName = strtolower($typeName);

        if (in_array($typeName, ['text', 'mediumtext', 'longtext', 'clob', 'ntext'], true)) {
            return true;
        }

        if (preg_match('/\((\d+)\)/', $type, $matches) === 1) {
            return (int) $matches[1] >= self::MIN_LENGTH;
        }

        // A length-less `character varying` / `varchar` (Postgres) is
        // unbounded.
        return in_array($typeName, ['varchar', 'character varying'], true) && ! str_contains($type, '(');
    }
}
