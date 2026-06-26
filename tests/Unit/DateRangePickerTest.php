<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Forms\Components\DateRangePicker;
use Happones\Kinetix\Tests\TestCase;

class DateRangePickerTest extends TestCase
{
    public function test_serializes_with_calendar_range_config_and_bounds(): void
    {
        $data = DateRangePicker::make('period')
            ->numberOfMonths(2)
            ->weekdayFormat('short')
            ->fixedWeeks()
            ->minValue('2026-01-01')
            ->maxValue('2026-12-31')
            ->toData('create', null);

        $this->assertSame('date-range-picker', $data->type);
        $this->assertTrue($data->useCalendar);
        $this->assertSame(2, $data->numberOfMonths);
        $this->assertSame('short', $data->weekdayFormat);
        $this->assertTrue($data->fixedWeeks);
        $this->assertSame('2026-01-01', $data->minValue);
        $this->assertSame('2026-12-31', $data->maxValue);
    }

    public function test_native_opts_out_of_the_calendar(): void
    {
        $data = DateRangePicker::make('period')->native()->toData('create', null);

        $this->assertFalse($data->useCalendar);
    }
}
