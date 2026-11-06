<?php

declare(strict_types=1);

namespace Happones\Kinetix\Confidential;

use Illuminate\Database\Eloquent\Model;

/**
 * Where a confidential value lives: its table, column and row. The cipher
 * seals a value to it (as GCM associated data), so a ciphertext copied into
 * another row or column — by anyone with write access to the database —
 * no longer decrypts.
 *
 * The TABLE is used, not the model's morph class: adding a morph map later
 * must not make every stored value unreadable. A row key that isn't a
 * scalar (a composite key) binds the value to its column only.
 */
final readonly class ConfidentialContext
{
    /**
     * @param bool $pending the stored value is the column-bound envelope this
     *                      process sealed for this very model instance, still
     *                      waiting to be bound to its new key
     */
    public function __construct(
        public string $table,
        public string $column,
        public int|string|null $rowKey,
        public bool $pending = false,
    ) {}

    /**
     * The context of `$column` on `$model`: its table and current key.
     * Given the `$stored` value about to be read, it also says whether that
     * is the envelope this process sealed for the instance before it had a
     * key ({@see ConfidentialBinding::sealedBeforeKey()}).
     */
    public static function for(Model $model, string $column, ?string $stored = null): self
    {
        $key = $model->getKey();

        return new self(
            $model->getTable(),
            $column,
            is_int($key) || (is_string($key) && $key !== '') ? $key : null,
            $stored !== null && ConfidentialBinding::sealedBeforeKey($model, $column) === $stored,
        );
    }

    /**
     * The associated data a value bound to `$boundKey` is sealed with (null:
     * bound to the column only, as a row not yet inserted is).
     */
    public function aad(int|string|null $boundKey): string
    {
        return "kinetix-confidential|2|{$this->table}|{$this->column}|".($boundKey ?? '');
    }

    /** This context for another row key. */
    public function withRowKey(int|string|null $rowKey): self
    {
        return new self($this->table, $this->column, $rowKey, $this->pending);
    }
}
