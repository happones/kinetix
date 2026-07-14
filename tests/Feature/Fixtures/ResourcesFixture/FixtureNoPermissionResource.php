<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature\Fixtures\ResourcesFixture;

use Happones\Kinetix\Resources\Resource;

/**
 * A discoverable Resource that opts OUT of permissions (default
 * `permissionFeature()` returns null) — discovery must skip it silently, so
 * scanning never over-grants.
 */
class FixtureNoPermissionResource extends Resource {}
