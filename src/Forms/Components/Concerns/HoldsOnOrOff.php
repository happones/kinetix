<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * A control that is only ever on or off: an empty state is off. It renders
 * off, so it submits `false` — not a `null` that a plain `boolean` rule
 * rejects although the user left the control as they saw it.
 */
trait HoldsOnOrOff
{
    public function hydrate(mixed $value, ?Model $record = null): mixed
    {
        return parent::hydrate($value ?? false, $record);
    }
}
