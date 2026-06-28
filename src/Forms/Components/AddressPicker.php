<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Data\FormFieldData;
use Happones\Kinetix\Support\Countries;
use Illuminate\Database\Eloquent\Model;

/**
 * A structured address field storing `{line1, line2, city, state, postalCode,
 * country}`. Renders text inputs for each part plus a searchable country select.
 * Persist the value to a JSON-cast column (or split it in dehydrateStateUsing).
 *
 *     AddressPicker::make('address');
 *     AddressPicker::make('address')->fields(['line1', 'city', 'country']);
 *     AddressPicker::make('address')->except('country'); // hide one or more parts
 *     AddressPicker::make('address')->countries(['US' => 'United States', 'MX' => 'Mexico']);
 */
class AddressPicker extends Field
{
    public const FIELDS = ['line1', 'line2', 'city', 'state', 'postalCode', 'country'];

    /**
     * @var array<int, string>
     */
    protected array $fields = self::FIELDS;

    /**
     * @var array<string, string>|null
     */
    protected ?array $countries = null;

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
     * Override the country select options (code => label). Defaults to the
     * built-in ISO 3166-1 list.
     *
     * @param array<string, string> $countries
     */
    public function countries(array $countries): static
    {
        $this->countries = $countries;

        return $this;
    }

    public function toData(string $operation, ?Model $record = null): ?FormFieldData
    {
        $data = parent::toData($operation, $record);

        if ($data === null) {
            return null;
        }

        $data->addressFields = $this->fields;
        $data->options       = $this->countries ?? Countries::all();

        return $data;
    }
}
