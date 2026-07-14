<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature\Fixtures\ResourcesFixture;

use Happones\Kinetix\Resources\Resource;

/**
 * A second discoverable Resource that opts INTO permissions (departments.*).
 */
class FixtureDepartmentResource extends Resource
{
    public static function permissionFeature(): ?string
    {
        return 'departments';
    }
}
