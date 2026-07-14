<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature\Fixtures\ResourcesFixture;

use Happones\Kinetix\Resources\Resource;

/**
 * A discoverable Resource that opts INTO permissions — its CRUD abilities
 * (employees.*) must be derived by discovery.
 */
class FixtureEmployeeResource extends Resource
{
    public static function permissionFeature(): ?string
    {
        return 'employees';
    }
}
