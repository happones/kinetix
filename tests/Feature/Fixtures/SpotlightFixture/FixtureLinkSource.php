<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature\Fixtures\SpotlightFixture;

use Happones\Kinetix\Data\SpotlightItemData;
use Happones\Kinetix\Spotlight\SpotlightSource;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A discoverable, container-resolvable spotlight source.
 */
class FixtureLinkSource implements SpotlightSource
{
    public function authorizedFor(?Authenticatable $user): bool
    {
        return true;
    }

    /**
     * @return array<int, SpotlightItemData>
     */
    public function search(string $query): array
    {
        return [];
    }
}
