<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Forms\Components\MonthPicker;
use Happones\Kinetix\Forms\Components\WeekPicker;
use Happones\Kinetix\Forms\Components\YearPicker;
use Happones\Kinetix\Tests\TestCase;

class MonthYearWeekPickerTest extends TestCase
{
    public function test_month_picker_serializes_with_bounds(): void
    {
        $data = MonthPicker::make('billed_at')
            ->minValue('2026-01')
            ->maxValue('2026-12')
            ->toData('create', null);

        $this->assertSame('month-picker', $data->type);
        $this->assertTrue($data->useCalendar);
        $this->assertSame('2026-01', $data->minValue);
        $this->assertSame('2026-12', $data->maxValue);
    }

    public function test_year_picker_native_and_bounds(): void
    {
        $data = YearPicker::make('year')->native()->minValue('2020')->maxValue('2030')->toData('create', null);

        $this->assertSame('year-picker', $data->type);
        $this->assertFalse($data->useCalendar);
        $this->assertSame('2020', $data->minValue);
        $this->assertSame('2030', $data->maxValue);
    }

    public function test_week_picker_type_and_locale(): void
    {
        $data = WeekPicker::make('sprint')->locale('es')->toData('create', null);

        $this->assertSame('week-picker', $data->type);
        $this->assertSame('es', $data->dateLocale);
    }
}
