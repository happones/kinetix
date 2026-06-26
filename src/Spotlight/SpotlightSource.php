<?php

declare(strict_types=1);

namespace Happones\Kinetix\Spotlight;

use Happones\Kinetix\Data\SpotlightItemData;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A spotlight result provider — a searchable model, a navigation link, or a
 * fast action. The controller asks each authorized source for items.
 */
interface SpotlightSource
{
    /**
     * Whether this source is visible to the user at all (source-level authz).
     */
    public function authorizedFor(?Authenticatable $user): bool;

    /**
     * Resolve items for the query (already authorization-filtered per result).
     *
     * @return array<int, SpotlightItemData>
     */
    public function search(string $query): array;
}
