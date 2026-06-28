<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Forms\Components\AddressPicker;
use Happones\Kinetix\Support\Countries;
use Happones\Kinetix\Tests\TestCase;

class AddressPickerTest extends TestCase
{
    public function test_serializes_with_type_and_default_countries(): void
    {
        $data = AddressPicker::make('address')->toData('create', null);

        $this->assertSame('address-picker', $data->type);
        $this->assertSame(AddressPicker::FIELDS, $data->addressFields);
        $this->assertSame(Countries::all(), $data->options);
        $this->assertArrayHasKey('US', $data->options);
    }

    public function test_fields_limits_and_orders_sub_fields(): void
    {
        $data = AddressPicker::make('address')
            ->fields(['country', 'city', 'unknown', 'line1'])
            ->toData('create', null);

        $this->assertSame(['country', 'city', 'line1'], $data->addressFields);
    }

    public function test_except_hides_a_single_sub_field(): void
    {
        $data = AddressPicker::make('address')
            ->except('country')
            ->toData('create', null);

        $this->assertSame(['line1', 'line2', 'city', 'state', 'postalCode'], $data->addressFields);
        $this->assertNotContains('country', $data->addressFields);
    }

    public function test_except_hides_multiple_sub_fields_and_composes_with_fields(): void
    {
        $data = AddressPicker::make('address')
            ->fields(['line1', 'line2', 'city', 'country'])
            ->except(['line2', 'country'])
            ->toData('create', null);

        $this->assertSame(['line1', 'city'], $data->addressFields);
    }

    public function test_countries_overrides_the_option_set(): void
    {
        $data = AddressPicker::make('address')
            ->countries(['US' => 'United States', 'MX' => 'Mexico'])
            ->toData('create', null);

        $this->assertSame(['US' => 'United States', 'MX' => 'Mexico'], $data->options);
    }
}
