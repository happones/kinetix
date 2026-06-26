<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Forms\Components\TimePicker;
use Happones\Kinetix\Tests\TestCase;

class TimePickerTest extends TestCase
{
    public function test_defaults_to_the_shadcn_time_ui(): void
    {
        $data = TimePicker::make('opens_at')->toData('create', null);

        $this->assertSame('time-picker', $data->type);
        $this->assertTrue($data->useCalendar);
        $this->assertFalse($data->hour12);
        $this->assertSame(5, $data->minuteStep);
    }

    public function test_native_opts_out_of_the_shadcn_ui(): void
    {
        $data = TimePicker::make('opens_at')->native()->toData('create', null);

        $this->assertFalse($data->useCalendar);
    }

    public function test_twelve_hour_and_minute_step(): void
    {
        $data = TimePicker::make('opens_at')
            ->twelveHour()
            ->minuteStep(15)
            ->toData('create', null);

        $this->assertTrue($data->hour12);
        $this->assertSame(15, $data->minuteStep);
    }
}
