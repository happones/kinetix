<?php

declare(strict_types=1);

namespace Happones\Kinetix\Locations;

/**
 * {@see DatabaseLocationProvider} plus a postal-code table: one row per
 * neighborhood, with its code and the keys of its state and city in the
 * provider's own tables — the shape of a SEPOMEX (Mexico) import or any
 * postal catalog:
 *
 *     'postal_codes' => ['table' => 'postal_codes', 'key' => 'id', 'code' => 'code',
 *                        'name' => 'name', 'state' => 'state_id', 'city' => 'city_id'],
 *
 * `active` and `soft_deletes` work as on the other tables. Index `code` and
 * `city_id`: they are what every lookup filters on.
 */
class DatabasePostalCodeProvider extends DatabaseLocationProvider implements PostalCodeProvider
{
    public function postalCode(string $country, string $code): ?array
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        $key   = $this->column('postal_codes', 'key', 'id');
        $name  = $this->column('postal_codes', 'name', 'name');
        $state = $this->column('postal_codes', 'state', 'state_id');
        $city  = $this->column('postal_codes', 'city', 'city_id');

        $rows = $this->table('postal_codes')
            ->where($this->column('postal_codes', 'code', 'code'), $code)
            ->orderBy($name)
            ->get([$key, $name, $state, $city]);

        if ($rows->isEmpty()) {
            return null;
        }

        $states = $rows->pluck($state)->map(fn (mixed $value): string => (string) $value)->unique()->values();
        $cities = $rows->pluck($city)->map(fn (mixed $value): string => (string) $value)->unique()->values();

        // A code of another country is no match — every state it touches
        // must be one of $country's.
        foreach ($states as $stateKey) {
            if ($this->stateLabel($country, $stateKey) === null) {
                return null;
            }
        }

        $stateKey  = $states->count() === 1 ? $states->first() : null;
        $cityKey   = $cities->count() === 1 ? $cities->first() : null;
        $cityLabel = $cityKey !== null ? $this->cityLabel($country, $stateKey, $cityKey) : null;

        $neighborhoods = [];

        foreach ($rows as $row) {
            $neighborhoods[] = ['value' => (string) $row->{$key}, 'label' => (string) $row->{$name}, 'postalCode' => $code];
        }

        return [
            'state'         => $stateKey !== null ? ['value' => $stateKey, 'label' => (string) $this->stateLabel($country, $stateKey)] : null,
            'city'          => $cityKey  !== null && $cityLabel !== null ? ['value' => $cityKey, 'label' => $cityLabel] : null,
            'neighborhoods' => $neighborhoods,
        ];
    }

    public function neighborhoods(string $country, ?string $state, string $city, string $search = '', int $limit = 200): array
    {
        if ($this->cityLabel($country, $state, $city) === null) {
            return [];
        }

        $key  = $this->column('postal_codes', 'key', 'id');
        $name = $this->column('postal_codes', 'name', 'name');
        $code = $this->column('postal_codes', 'code', 'code');

        $query = $this->table('postal_codes')->where($this->column('postal_codes', 'city', 'city_id'), $city);

        $this->whereNameContains($query, $name, $search);

        $neighborhoods = [];

        foreach ($query->orderBy($name)->orderBy($code)->limit(max(1, $limit))->get([$key, $name, $code]) as $row) {
            $neighborhoods[] = ['value' => (string) $row->{$key}, 'label' => (string) $row->{$name}, 'postalCode' => (string) $row->{$code}];
        }

        return $neighborhoods;
    }

    public function neighborhood(string $country, ?string $state, string $city, string $neighborhood): ?array
    {
        if ($this->cityLabel($country, $state, $city) === null) {
            return null;
        }

        $name = $this->column('postal_codes', 'name', 'name');
        $code = $this->column('postal_codes', 'code', 'code');

        $row = $this->table('postal_codes')
            ->where($this->column('postal_codes', 'key', 'id'), $neighborhood)
            ->where($this->column('postal_codes', 'city', 'city_id'), $city)
            ->first([$name, $code]);

        return $row !== null ? ['label' => (string) $row->{$name}, 'postalCode' => (string) $row->{$code}] : null;
    }
}
