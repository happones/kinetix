<?php

declare(strict_types=1);

namespace Happones\Kinetix\Locations;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates an AddressPicker value: its shape, the parts marked required, and
 * — with a location provider — that the state belongs to the country, the
 * city to the state and (from a {@see PostalCodeProvider}) the neighborhood
 * to the city. A part typed as text is accepted only where the provider has
 * none to choose from (a country without states, say).
 */
class AddressRule implements ValidationRule
{
    /**
     * @param array<int, string>         $fields    the parts the picker shows
     * @param array<int, string>         $required  the parts that can't be blank
     * @param array<string, string>|null $countries the selectable countries (code => name)
     */
    public function __construct(
        protected array $fields,
        protected array $required = [],
        protected ?array $countries = null,
        protected ?LocationProvider $provider = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === []) {
            return;
        }

        if (! is_array($value)) {
            $fail(__('kinetix.address_invalid'));

            return;
        }

        foreach ([...$this->fields, 'stateId', 'cityId', 'neighborhoodId'] as $part) {
            $part = $value[$part] ?? null;

            if ($part !== null && (! is_scalar($part) || mb_strlen((string) $part) > 255)) {
                $fail(__('kinetix.address_invalid'));

                return;
            }
        }

        foreach ($this->required as $part) {
            if (in_array($part, $this->fields, true) && self::blank($value[$part] ?? null)) {
                $fail(__('kinetix.address_part_required', ['part' => __('kinetix.'.AddressParts::LABELS[$part])]));
            }
        }

        $country = self::blank($value['country'] ?? null) ? null : strtoupper((string) $value['country']);

        if ($country !== null && in_array('country', $this->fields, true) && $this->countries !== null && ! array_key_exists($country, $this->countries)) {
            $fail(__('kinetix.address_country_invalid'));

            return;
        }

        if ($this->provider === null) {
            return;
        }

        $stateId = self::blank($value['stateId'] ?? null) ? null : (string) $value['stateId'];
        $cityId  = self::blank($value['cityId'] ?? null) ? null : (string) $value['cityId'];

        if ($country === null) {
            if ($stateId !== null || $cityId !== null) {
                $fail(__('kinetix.address_country_first'));
            }

            return;
        }

        if (in_array('state', $this->fields, true)) {
            if ($stateId !== null && $this->provider->stateLabel($country, $stateId) === null) {
                $fail(__('kinetix.address_state_invalid'));

                return;
            }

            if ($stateId === null && ! self::blank($value['state'] ?? null) && $this->provider->states($country, '', 1) !== []) {
                $fail(__('kinetix.address_state_pick'));

                return;
            }
        } else {
            $stateId = null;
        }

        if (in_array('city', $this->fields, true)) {
            if ($cityId !== null && $this->provider->cityLabel($country, $stateId, $cityId) === null) {
                $fail(__('kinetix.address_city_invalid'));

                return;
            }

            if ($cityId === null && ! self::blank($value['city'] ?? null) && $this->provider->cities($country, $stateId, '', 1) !== []) {
                $fail(__('kinetix.address_city_pick'));

                return;
            }
        } else {
            $cityId = null;
        }

        if (! $this->provider instanceof PostalCodeProvider || ! in_array('neighborhood', $this->fields, true)) {
            return;
        }

        $neighborhoodId = self::blank($value['neighborhoodId'] ?? null) ? null : (string) $value['neighborhoodId'];

        if ($neighborhoodId !== null) {
            if ($cityId === null || $this->provider->neighborhood($country, $stateId, $cityId, $neighborhoodId) === null) {
                $fail(__('kinetix.address_neighborhood_invalid'));
            }

            return;
        }

        if ($cityId !== null && ! self::blank($value['neighborhood'] ?? null) && $this->provider->neighborhoods($country, $stateId, $cityId, '', 1) !== []) {
            $fail(__('kinetix.address_neighborhood_pick'));
        }
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
