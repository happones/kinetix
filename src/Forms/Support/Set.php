<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Support;

/**
 * Write access to the form's state during a server-side recompute — the `$set`
 * an `afterStateUpdated()` closure receives to push derived values back to the
 * client:
 *
 *     Select::make('country')->live()
 *         ->afterStateUpdated(fn (Set $set) => $set('state', null)); // clear dependent
 *
 * The recompute collects everything written here ({@see changes()}) and
 * returns it alongside the fresh schema, so `KinetixForm` applies the new
 * values without the user touching those fields. It holds a reference to the
 * recompute's working state so a later `$get` in the same pass sees the write.
 */
final class Set
{
    /**
     * What this pass set, keyed by path, so the response ships just the deltas.
     *
     * @var array<string, mixed>
     */
    private array $changes = [];

    /**
     * @param array<string, mixed> $state Working state, passed by reference so
     *                                    writes are visible to the recompute and
     *                                    to subsequent reads in the same pass.
     */
    public function __construct(private array &$state) {}

    /**
     * Set one field's value (dot-notation supported for nested/repeater state).
     *
     * The change ships keyed by its PATH (`items.0.qty`), not nested: nested,
     * the client's merge replaced the whole `items` array with `[{qty: 5}]`
     * and every other row and field in it was lost.
     */
    public function __invoke(string $key, mixed $value): void
    {
        data_set($this->state, $key, $value);
        $this->changes[$key] = $value;
    }

    /**
     * What was set during this pass, keyed by path (`items.0.qty`).
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        return $this->changes;
    }
}
