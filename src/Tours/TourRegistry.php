<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tours;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * The product-tour catalog. Declare tours per module from any service
 * provider; the `kinetix_tours` Inertia share hands the authorized ones to the
 * global `<KinetixTours />` host. Bound as a singleton so declarations
 * accumulate, mirroring the permission/spotlight registries.
 */
class TourRegistry
{
    /**
     * @var array<string, Tour>
     */
    protected array $tours = [];

    /**
     * Declare (or fetch) a tour by id.
     */
    public function tour(string $id): Tour
    {
        return $this->tours[$id] ??= new Tour($id);
    }

    /**
     * @return array<string, Tour>
     */
    public function tours(): array
    {
        return $this->tours;
    }

    /**
     * The tours the user is allowed to receive, serialized for the frontend.
     * A tour with a `permission()` the Gate denies is omitted entirely — its
     * steps never reach a viewer who can't use the UI they point at.
     *
     * @return array<int, array<string, mixed>>
     */
    public function authorizedFor(?Authenticatable $user): array
    {
        $authorized = [];

        foreach ($this->tours as $tour) {
            $permission = $tour->getPermission();

            if ($permission !== null && ! Gate::forUser($user)->allows($permission)) {
                continue;
            }

            $authorized[] = $tour->toArray();
        }

        return $authorized;
    }
}
