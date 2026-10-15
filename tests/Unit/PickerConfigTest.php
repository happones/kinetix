<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Forms\Components\DatePicker;
use Happones\Kinetix\Forms\Components\DateRangePicker;
use Happones\Kinetix\Forms\Components\DateTimePicker;
use Happones\Kinetix\Forms\Components\MonthPicker;
use Happones\Kinetix\Forms\Components\TimePicker;
use Happones\Kinetix\Forms\Components\WeekPicker;
use Happones\Kinetix\Forms\Components\YearPicker;
use Happones\Kinetix\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The v0.141.0 picker variants must survive serialization: `confirm`,
 * `todayButton` and `closeOnSelect` travel through Field::toData() into the
 * payload the Vue field wrappers read.
 */
class PickerConfigTest extends TestCase
{
    public function test_date_picker_serializes_its_behavior_flags(): void
    {
        $data = DatePicker::make('published_at')
            ->confirm()
            ->todayButton()
            ->closeOnSelect(false)
            ->toData('create');

        $this->assertNotNull($data);
        $this->assertTrue($data->confirm);
        $this->assertTrue($data->showToday);
        $this->assertFalse($data->closeOnSelect);
    }

    public function test_date_picker_defaults_match_the_shadcn_behavior(): void
    {
        $data = DatePicker::make('published_at')->toData('create');

        $this->assertNotNull($data);
        $this->assertFalse($data->confirm);
        $this->assertFalse($data->showToday);
        $this->assertTrue($data->closeOnSelect);
    }

    public function test_date_range_picker_serializes_the_same_behavior_flags(): void
    {
        $data = DateRangePicker::make('period')
            ->confirm()
            ->todayButton()
            ->closeOnSelect(false)
            ->timezone('Asia/Tokyo')
            ->toData('create');

        $this->assertNotNull($data);
        $this->assertTrue($data->confirm);
        $this->assertTrue($data->showToday);
        $this->assertFalse($data->closeOnSelect);
        $this->assertSame('Asia/Tokyo', $data->timezone);
    }

    public function test_period_pickers_serialize_the_same_behavior_flags(): void
    {
        foreach ([MonthPicker::class, WeekPicker::class, YearPicker::class] as $picker) {
            $data = $picker::make('period')
                ->confirm()
                ->todayButton()
                ->closeOnSelect(false)
                ->timezone('Asia/Tokyo')
                ->toData('create');

            $this->assertNotNull($data, $picker);
            $this->assertTrue($data->confirm, $picker);
            $this->assertTrue($data->showToday, $picker);
            $this->assertFalse($data->closeOnSelect, $picker);
            $this->assertSame('Asia/Tokyo', $data->timezone, $picker);
        }
    }

    public function test_pickers_default_their_timezone_to_the_app_timezone(): void
    {
        config()->set('app.timezone', 'America/Mexico_City');

        $this->assertSame(
            'America/Mexico_City',
            DatePicker::make('published_at')->toData('create')?->timezone,
        );
        $this->assertSame(
            'America/Mexico_City',
            DateRangePicker::make('period')->toData('create')?->timezone,
        );
        $this->assertSame(
            'America/Mexico_City',
            DateTimePicker::make('scheduled_at')->toData('create')?->timezone,
        );
        $this->assertSame(
            'America/Mexico_City',
            TimePicker::make('opens_at')->toData('create')?->timezone,
        );
    }

    public function test_the_timezone_is_overridable_per_component(): void
    {
        config()->set('app.timezone', 'UTC');

        $data = DateTimePicker::make('scheduled_at')
            ->timezone('Asia/Tokyo')
            ->toData('create');

        $this->assertSame('Asia/Tokyo', $data?->timezone);
    }

    public function test_datetime_and_time_pickers_serialize_confirm(): void
    {
        $datetime = DateTimePicker::make('scheduled_at')->confirm()->toData('create');
        $time     = TimePicker::make('opens_at')->confirm()->toData('create');

        $this->assertNotNull($datetime);
        $this->assertNotNull($time);
        $this->assertTrue($datetime->confirm);
        $this->assertTrue($time->confirm);
        $this->assertFalse(DateTimePicker::make('x')->toData('create')?->confirm);
    }

    public function test_pickers_serialize_the_typed_input_and_its_format(): void
    {
        $date     = DatePicker::make('starts_on')->typed('dd/mm/yyyy')->toData('create');
        $datetime = DateTimePicker::make('scheduled_at')->typed('mm/dd/yyyy hh:mm a')->toData('create');
        $time     = TimePicker::make('opens_at')->typed()->toData('create');
        $month    = MonthPicker::make('period')->typed('yyyy-mm')->toData('create');

        $this->assertTrue($date?->typed);
        $this->assertSame('dd/mm/yyyy', $date->inputFormat);
        $this->assertSame('mm/dd/yyyy hh:mm a', $datetime?->inputFormat);
        $this->assertTrue($time?->typed);
        $this->assertNull($time->inputFormat);
        $this->assertSame('yyyy-mm', $month?->inputFormat);

        $this->assertFalse(DatePicker::make('x')->toData('create')?->typed);
        $this->assertFalse(DatePicker::make('x')->typed('dd/mm/yyyy')->typed(false)->toData('create')?->typed);
    }

    /**
     * @return array<string, array{0: \Closure(): mixed, 1: string}>
     */
    public static function unfillableFormats(): array
    {
        return [
            'date without a year'      => [fn () => DatePicker::make('d')->typed('dd/mm'), 'year'],
            'month without a month'    => [fn () => MonthPicker::make('m')->typed('yyyy'), 'mm'],
            'time without minutes'     => [fn () => TimePicker::make('t')->typed('HH'), 'mm'],
            'datetime without a time'  => [fn () => DateTimePicker::make('dt')->typed('dd/mm/yyyy'), 'hour'],
            '12-hour clock without am' => [fn () => TimePicker::make('t')->typed('hh:mm'), 'AM/PM'],
        ];
    }

    #[DataProvider('unfillableFormats')]
    public function test_a_typed_format_missing_a_part_is_refused_where_it_is_written(\Closure $build, string $missing): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($missing);

        $build();
    }
}
