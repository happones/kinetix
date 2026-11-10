<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Fakes;

use Illuminate\Database\Eloquent\Model;

/**
 * An ownership rule: owners of the team slugged "beta" only.
 */
class RoutingOwnerRule
{
    public static function owns(mixed $user, ?Model $team): bool
    {
        return $team?->getAttribute('slug') === 'beta';
    }
}
