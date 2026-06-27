<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Data\FormFieldData;
use Happones\Kinetix\Support\Countries;
use Happones\Kinetix\Support\DialCodes;
use Illuminate\Database\Eloquent\Model;

/**
 * An international phone field: a searchable country selector (flag + dial code)
 * plus a national number input. Stores the full E.164-style string, e.g.
 * "+5215512345678".
 *
 *     PhoneInput::make('phone')->defaultCountry('MX');
 *     PhoneInput::make('phone')->countries(['US', 'MX', 'CA']);
 */
class PhoneInput extends Field
{
    protected string $defaultCountry = 'US';

    /**
     * @var array<int, string>|null
     */
    protected ?array $only = null;

    protected function getType(): string
    {
        return 'phone-input';
    }

    public function defaultCountry(string $code): static
    {
        $this->defaultCountry = strtoupper($code);

        return $this;
    }

    /**
     * Restrict the selectable countries (ISO alpha-2 codes).
     *
     * @param array<int, string> $codes
     */
    public function countries(array $codes): static
    {
        $this->only = array_map('strtoupper', $codes);

        return $this;
    }

    public function toData(string $operation, ?Model $record = null): ?FormFieldData
    {
        $data = parent::toData($operation, $record);

        if ($data === null) {
            return null;
        }

        $names = Countries::all();
        $dials = DialCodes::all();
        $codes = $this->only ?? array_keys($dials);

        $countries = [];
        foreach ($codes as $code) {
            if (! isset($dials[$code])) {
                continue;
            }
            $countries[] = [
                'code' => $code,
                'name' => $names[$code] ?? $code,
                'dial' => $dials[$code],
            ];
        }

        usort($countries, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        $data->phoneConfig = [
            'defaultCountry' => $this->defaultCountry,
            'countries'      => $countries,
        ];

        return $data;
    }
}
