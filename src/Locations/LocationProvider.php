<?php

declare(strict_types=1);

namespace Happones\Kinetix\Locations;

/**
 * Where an AddressPicker's countries, states and cities come from.
 *
 * Countries are keyed by ISO 3166-1 alpha-2 code; states and cities by
 * whatever key the source uses (a database id, an API code). Lists come back
 * as `[{value, label}]` in display order — a list, not a map, so numeric keys
 * keep the order they were given.
 *
 * Implement it to read locations from anywhere (an API, your own tables) and
 * register it in `kinetix.locations.providers`:
 *
 *     'providers' => ['geo' => ['driver' => \App\Locations\GeoApiProvider::class]],
 *
 * Kinetix caches every unfiltered list and label lookup ({@see CachedLocationProvider}),
 * so an implementation can query its source plainly.
 */
interface LocationProvider
{
    /**
     * Every selectable country, code => name, in display order.
     *
     * @return array<string, string>
     */
    public function countries(): array;

    /**
     * The states of a country whose name contains $search (all when empty),
     * at most $limit of them.
     *
     * @return list<array{value: string, label: string}>
     */
    public function states(string $country, string $search = '', int $limit = 1000): array;

    /**
     * The cities of a state — of the whole country when $state is null —
     * whose name contains $search, at most $limit of them.
     *
     * @return list<array{value: string, label: string}>
     */
    public function cities(string $country, ?string $state, string $search = '', int $limit = 50): array;

    /**
     * The name of a state, or null when it doesn't exist or isn't in $country.
     */
    public function stateLabel(string $country, string $state): ?string;

    /**
     * The name of a city, or null when it doesn't exist or isn't in $state
     * (in $country when $state is null).
     */
    public function cityLabel(string $country, ?string $state, string $city): ?string;
}
