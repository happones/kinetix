<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Forms\Components\NumberField;
use Happones\Kinetix\Tables\Columns\NumberInputColumn;
use Happones\Kinetix\Tests\TestCase;

class NumberFieldTest extends TestCase
{
    public function test_field_serializes_bounds_and_step(): void
    {
        $data = NumberField::make('quantity')->min(0)->max(99)->step(5)->toData('create', null);

        $this->assertSame('number-field', $data->type);
        $this->assertSame(0.0, $data->numberConfig['min']);
        $this->assertSame(99.0, $data->numberConfig['max']);
        $this->assertSame(5.0, $data->numberConfig['step']);
        $this->assertSame('decimal', $data->numberConfig['format']);
    }

    public function test_field_currency_and_percent_formats(): void
    {
        $currency = NumberField::make('price')->currency('USD')->toData('create', null);
        $this->assertSame('currency', $currency->numberConfig['format']);
        $this->assertSame('USD', $currency->numberConfig['currency']);

        $percent = NumberField::make('rate')->percent()->decimals(0, 2)->toData('create', null);
        $this->assertSame('percent', $percent->numberConfig['format']);
        $this->assertSame(['min' => 0, 'max' => 2], $percent->numberConfig['decimals']);
    }

    public function test_editable_column_serializes_number_config(): void
    {
        $column = NumberInputColumn::make('stock')->min(0)->step(1);

        $this->assertTrue($column->isEditable());

        $data = $column->toData();
        $this->assertSame('number-input', $data->type);
        $this->assertSame(0.0, $data->numberConfig['min']);
        $this->assertSame(1.0, $data->numberConfig['step']);
    }
}
