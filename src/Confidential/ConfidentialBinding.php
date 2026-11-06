<?php

declare(strict_types=1);

namespace Happones\Kinetix\Confidential;

use Happones\Kinetix\Confidential\Casts\ConfidentialCast;
use Illuminate\Database\Eloquent\Model;

/**
 * Binds confidential values to their row.
 *
 * The cast encrypts a value when it is assigned, and an auto-increment row
 * has no key until it is inserted: such a value is sealed to its column
 * only. On `eloquent.created` it is re-sealed to the new row's key (one
 * UPDATE per created row holding confidential values, no model events, no
 * timestamps). On `eloquent.replicating` a value bound to the source row is
 * re-sealed to the column, so the replica can be saved and then bound to
 * its own key.
 *
 * Writes that skip model events (`withoutEvents()`, query-builder inserts)
 * leave the value bound to its column only.
 *
 * @internal
 */
final class ConfidentialBinding
{
    /**
     * Confidential columns per model class.
     *
     * @var array<class-string, list<string>>
     */
    private static array $columns = [];

    /**
     * The column-bound envelopes this process sealed per model instance
     * (column => envelope), until the instance is bound to its key. Keyed
     * by the instance itself: a value read fresh from the database (a
     * `refresh()`, a new query) never matches.
     *
     * @var \WeakMap<Model, array<string, string>>|null
     */
    private static ?\WeakMap $sealed = null;

    /**
     * Remember the envelope the cast sealed for `$model`'s `$column` before
     * the row had a key.
     */
    public static function rememberSealedBeforeKey(Model $model, string $column, string $envelope): void
    {
        self::$sealed ??= new \WeakMap;
        self::$sealed[$model] = [...(self::$sealed[$model] ?? []), $column => $envelope];
    }

    /** The column-bound envelope this process sealed for `$model`'s `$column`, if still unbound. */
    public static function sealedBeforeKey(Model $model, string $column): ?string
    {
        return self::$sealed !== null && isset(self::$sealed[$model]) ? (self::$sealed[$model][$column] ?? null) : null;
    }

    private static function forgetSealed(Model $model): void
    {
        if (self::$sealed !== null) {
            unset(self::$sealed[$model]);
        }
    }

    /**
     * `eloquent.created: *` — re-seal column-bound values to the new key.
     *
     * @param array<int, mixed> $payload
     */
    public function created(string $event, array $payload): void
    {
        $model = $payload[0] ?? null;

        if (! $model instanceof Model) {
            return;
        }

        $columns = self::columnsOf($model);

        if ($columns === []) {
            return;
        }

        $context = ConfidentialContext::for($model, '');

        if ($context->rowKey === null) {
            return;
        }

        // Whatever happens below, the instance now has a key: its values
        // are no longer "sealed before the key" for strict reads.
        self::forgetSealed($model);

        $cipher     = app(ConfidentialCipher::class);
        $attributes = $model->getAttributes();
        $updates    = [];

        foreach ($columns as $column) {
            $raw = $attributes[$column] ?? null;

            if (! is_string($raw) || ! self::isColumnBound($cipher, $raw)) {
                continue;
            }

            $plaintext = $cipher->decrypt($raw, new ConfidentialContext($context->table, $column, null));

            $updates[$column] = $cipher->encrypt($plaintext, ConfidentialContext::for($model, $column));
        }

        if ($updates === []) {
            return;
        }

        $model->newQueryWithoutScopes()->whereKey($model->getKey())->toBase()->update($updates);

        $model->setRawAttributes(array_replace($attributes, $updates));
        $model->syncOriginalAttributes(array_keys($updates));
    }

    /**
     * `eloquent.replicating: *` — re-seal row-bound values to the column, so
     * the replica (which has no key yet) can read and save them.
     *
     * @param array<int, mixed> $payload
     */
    public function replicating(string $event, array $payload): void
    {
        $model = $payload[0] ?? null;

        if (! $model instanceof Model) {
            return;
        }

        $columns = self::columnsOf($model);

        if ($columns === []) {
            return;
        }

        $cipher     = app(ConfidentialCipher::class);
        $attributes = $model->getAttributes();
        $updates    = [];

        foreach ($columns as $column) {
            $raw = $attributes[$column] ?? null;

            if (! is_string($raw)) {
                continue;
            }

            $envelope = $cipher->describe($raw);

            if ($envelope === null || $envelope['v'] !== 2 || $envelope['r'] === null) {
                continue;
            }

            $source    = new ConfidentialContext($model->getTable(), $column, $envelope['r']);
            $plaintext = $cipher->decrypt($raw, $source);

            $updates[$column] = $cipher->encrypt($plaintext, $source->withRowKey(null));
        }

        if ($updates !== []) {
            $model->setRawAttributes(array_replace($attributes, $updates));

            foreach ($updates as $column => $envelope) {
                self::rememberSealedBeforeKey($model, $column, $envelope);
            }
        }
    }

    /** Forget the memoized columns (tests). */
    public static function flush(): void
    {
        self::$sealed  = null;
        self::$columns = [];
    }

    /**
     * The model's confidential columns, memoized per class: a model without
     * any costs one array lookup per event.
     *
     * @return list<string>
     */
    private static function columnsOf(Model $model): array
    {
        return self::$columns[$model::class] ??= array_values(array_filter(
            array_keys($model->getCasts()),
            static fn (string $column): bool => ConfidentialCast::appliesTo($model, $column),
        ));
    }

    /** Whether `$raw` is a v2 envelope bound to its column only. */
    private static function isColumnBound(ConfidentialCipher $cipher, string $raw): bool
    {
        $envelope = $cipher->describe($raw);

        return $envelope !== null && $envelope['v'] === 2 && $envelope['r'] === null;
    }
}
