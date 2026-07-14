<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature\Fixtures\ResourcesFixture;

use Happones\Kinetix\Resources\Resource;

/**
 * An abstract Resource subclass in the discovered directory — must be excluded
 * from discovery (it can't be instantiated).
 */
abstract class AbstractFixtureResource extends Resource
{
    public static function permissionFeature(): ?string
    {
        return 'abstract-should-not-appear';
    }
}
