<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Data\FormFieldData;
use Happones\Kinetix\Locations\AddressParts;
use Happones\Kinetix\Locations\AddressRule;
use Happones\Kinetix\Locations\KinetixLocations;
use Happones\Kinetix\Locations\LocationProvider;
use Happones\Kinetix\Support\Countries;
use Happones\Kinetix\Support\SignedDescriptor;
use Illuminate\Database\Eloquent\Model;

/**
 * A structured address field storing `{line1, line2, city, state, postalCode,
 * country}` — plus `stateId` / `cityId` in locations mode. Persist the value to
 * a JSON-cast column (or split it in dehydrateStateUsing).
 *
 * Two modes:
 *  - free text (default): text inputs for each part plus a searchable country
 *    select from the built-in ISO 3166-1 list;
 *  - locations: country → state → city selects, each listing the children of
 *    the one before, from a location provider (nnjeim/world,
 *    altwaireb/laravel-countries, your own tables or any source — see
 *    `kinetix.locations`).
 *
 *     AddressPicker::make('address');
 *     AddressPicker::make('address')->locations();          // the default provider
 *     AddressPicker::make('address')->locations('countries');
 *     AddressPicker::make('address')->fields(['line1', 'city', 'country']);
 *     AddressPicker::make('address')->except('country'); // hide one or more parts
 *     AddressPicker::make('address')->requiredFields(['line1', 'city', 'country']);
 *     AddressPicker::make('address')->countries(['US' => 'United States', 'MX' => 'Mexico']);
 */
class AddressPicker extends Field
{
    public const FIELDS = AddressParts::FIELDS;

    /**
     * @var array<int, string>
     */
    protected array $fields = self::FIELDS;

    /**
     * @var array<int, string>
     */
    protected array $requiredFields = [];

    /**
     * @var array<string, string>|null
     */
    protected ?array $countries = null;

    /**
     * The location provider's name (locations mode), null for free text.
     */
    protected ?string $locations = null;

    protected function getType(): string
    {
        return 'address-picker';
    }

    /**
     * Limit which sub-fields are shown (and their order).
     *
     * @param array<int, string> $fields
     */
    public function fields(array $fields): static
    {
        $this->fields = array_values(array_intersect($fields, self::FIELDS));

        return $this;
    }

    /**
     * Hide one or more sub-fields, keeping the rest (and their order). The
     * ergonomic inverse of {@see fields()} — e.g. `->except('country')` or
     * `->except(['line2', 'country'])`.
     *
     * @param array<int, string>|string $fields
     */
    public function except(array|string $fields): static
    {
        $exclude      = is_array($fields) ? $fields : [$fields];
        $this->fields = array_values(array_diff($this->fields, $exclude));

        return $this;
    }

    /**
     * The parts that can't be left blank once the address is filled in at
     * all. (`->required()` makes the address itself required.)
     *
     * @param array<int, string> $fields
     */
    public function requiredFields(array $fields): static
    {
        $this->requiredFields = array_values(array_intersect($fields, self::FIELDS));

        return $this;
    }

    /**
     * Override the country select options (code => label). Defaults to the
     * built-in ISO 3166-1 list — or, in locations mode, the provider's
     * countries (this then narrows them to the codes given).
     *
     * @param array<string, string> $countries
     */
    public function countries(array $countries): static
    {
        $this->countries = $countries;

        return $this;
    }

    /**
     * Pick the state and city from a location provider instead of typing
     * them: a name from `kinetix.locations.providers` (`world`, `countries`,
     * your own) or a LocationProvider class. Null = `kinetix.locations.default`.
     */
    public function locations(?string $provider = null): static
    {
        $this->locations = $provider ?? app(KinetixLocations::class)->defaultName();

        return $this;
    }

    /**
     * Type the state and city as free text (the default) — undoes locations().
     */
    public function freeText(): static
    {
        $this->locations = null;

        return $this;
    }

    public function usesLocations(): bool
    {
        return $this->locations !== null;
    }

    public function getLocationProvider(): ?LocationProvider
    {
        return $this->locations !== null ? app(KinetixLocations::class)->provider($this->locations) : null;
    }

    /**
     * The selectable countries, code => name.
     *
     * @return array<string, string>
     */
    public function getCountries(): array
    {
        $provider = $this->getLocationProvider();

        if ($provider === null) {
            return $this->countries ?? Countries::all();
        }

        $countries = $provider->countries();

        return $this->countries === null
            ? $countries
            : array_intersect_key($countries, $this->countries);
    }

    public function getRules(?Model $record = null): array
    {
        $rules   = parent::getRules($record);
        $rules[] = new AddressRule(
            $this->fields,
            $this->requiredFields,
            in_array('country', $this->fields, true) ? $this->getCountries() : null,
            $this->getLocationProvider(),
        );

        if (! in_array('required', $rules, true) && ! in_array('nullable', $rules, true)) {
            array_unshift($rules, 'nullable');
        }

        return $rules;
    }

    /**
     * Keep only the address parts, and in locations mode store the provider's
     * names for the chosen state and city (what was posted is only a label).
     */
    public function dehydrate(mixed $value, ?Model $record = null): mixed
    {
        return parent::dehydrate($this->normalize($value), $record);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function normalize(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $address = [];

        foreach (self::FIELDS as $part) {
            $partValue      = $value[$part] ?? null;
            $address[$part] = is_scalar($partValue) && trim((string) $partValue) !== '' ? trim((string) $partValue) : null;
        }

        if ($address['country'] !== null) {
            $address['country'] = strtoupper($address['country']);
        }

        $provider = $this->getLocationProvider();

        if ($provider === null) {
            return $address;
        }

        $stateId = is_scalar($value['stateId'] ?? null) && (string) $value['stateId'] !== '' ? (string) $value['stateId'] : null;
        $cityId  = is_scalar($value['cityId'] ?? null)  && (string) $value['cityId']  !== '' ? (string) $value['cityId'] : null;

        $address['stateId'] = null;
        $address['cityId']  = null;

        if ($address['country'] === null) {
            return $address;
        }

        if ($stateId !== null && ($label = $provider->stateLabel($address['country'], $stateId)) !== null) {
            $address['state']   = $label;
            $address['stateId'] = $stateId;
        }

        if ($cityId !== null && ($label = $provider->cityLabel($address['country'], $address['stateId'], $cityId)) !== null) {
            $address['city']   = $label;
            $address['cityId'] = $cityId;
        }

        return $address;
    }

    public function toData(string $operation, ?Model $record = null): ?FormFieldData
    {
        $data = parent::toData($operation, $record);

        if ($data === null) {
            return null;
        }

        $data->addressFields   = $this->fields;
        $data->addressRequired = $this->requiredFields;
        $data->options         = $this->getCountries();

        if ($this->locations !== null) {
            $data->addressLocations = SignedDescriptor::seal(['locations' => $this->locations]);
        }

        return $data;
    }
}
