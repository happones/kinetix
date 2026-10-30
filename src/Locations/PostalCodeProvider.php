<?php

declare(strict_types=1);

namespace Happones\Kinetix\Locations;

/**
 * A location provider that also knows postal codes and the neighborhoods
 * (colonias, barrios…) each one serves — what a country that addresses by
 * postal code needs: typing the code fills the state and city and lists the
 * neighborhoods to pick from; picking a neighborhood fills the code.
 *
 * Implement it next to {@see LocationProvider} (the database driver does when
 * its config has a `postal_codes` table). Keys are the provider's own, as for
 * states and cities; every neighborhood belongs to one city and has one code.
 */
interface PostalCodeProvider extends LocationProvider
{
    /**
     * What a postal code of $country tells: its state and city (null when
     * the code spans more than one) and the neighborhoods it serves, in
     * display order. Null when the code doesn't exist in $country.
     *
     * @return array{state: array{value: string, label: string}|null, city: array{value: string, label: string}|null, neighborhoods: list<array{value: string, label: string, postalCode: string}>}|null
     */
    public function postalCode(string $country, string $code): ?array;

    /**
     * The neighborhoods of a city whose name contains $search (all when
     * empty), at most $limit of them, each with its postal code.
     *
     * @return list<array{value: string, label: string, postalCode: string}>
     */
    public function neighborhoods(string $country, ?string $state, string $city, string $search = '', int $limit = 200): array;

    /**
     * A neighborhood's name and postal code, or null when it doesn't exist or
     * isn't in $city.
     *
     * @return array{label: string, postalCode: string}|null
     */
    public function neighborhood(string $country, ?string $state, string $city, string $neighborhood): ?array;
}
