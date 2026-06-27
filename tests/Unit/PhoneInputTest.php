<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Forms\Components\PhoneInput;
use Happones\Kinetix\Support\DialCodes;
use Happones\Kinetix\Tests\TestCase;

class PhoneInputTest extends TestCase
{
    public function test_serializes_default_country_and_full_country_list(): void
    {
        $data = PhoneInput::make('phone')->defaultCountry('mx')->toData('create', null);

        $this->assertSame('phone-input', $data->type);
        $this->assertSame('MX', $data->phoneConfig['defaultCountry']);
        $this->assertNotEmpty($data->phoneConfig['countries']);

        $codes = array_column($data->phoneConfig['countries'], 'code');
        $this->assertContains('US', $codes);
        $this->assertContains('MX', $codes);
    }

    public function test_countries_restricts_and_sorts_the_list(): void
    {
        $data = PhoneInput::make('phone')->countries(['us', 'mx', 'ca'])->toData('create', null);

        $list = $data->phoneConfig['countries'];
        $this->assertCount(3, $list);
        // Sorted by name: Canada, Mexico, United States.
        $this->assertSame(['CA', 'MX', 'US'], array_column($list, 'code'));
        $this->assertSame('52', collect($list)->firstWhere('code', 'MX')['dial']);
    }

    public function test_dial_codes_lookup(): void
    {
        $this->assertSame('1', DialCodes::for('US'));
        $this->assertSame('52', DialCodes::for('mx'));
        $this->assertSame('44', DialCodes::for('GB'));
        $this->assertNull(DialCodes::for('ZZ'));
    }
}
