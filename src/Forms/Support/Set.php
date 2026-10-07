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
 * The recompute collects everything written here and returns it alongside the
 * fresh schema, so `KinetixForm` applies the new values without the user
 * touching those fields. It holds a reference to the recompute's working state
 * so a later `$get` in the same pass sees the write.
 */
final class Set
{
    /**
     * @param array<string, mixed> $state   Working state, passed by reference so
     *                                      writes are visible to the recompute and
     *                                      to subsequent reads in the same pass.
     * @param array<string, mixed> $changes Accumulates only what this pass set,
     *                                      so the response ships just the deltas.
     */
    public function __construct(
        private array &$state,
        private array &$changes,
    ) {}

    /**
     * Set one field's value (dot-notation supported for nested/repeater state).
     */
    public function __invoke(string $key, mixed $value): void
    {
        data_set($this->state, $key, $value);
        data_set($this->changes, $key, $value);
    }
}
