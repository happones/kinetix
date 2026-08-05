<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Forms\Components\DatePicker;
use Happones\Kinetix\Forms\Components\DateTimePicker;
use Happones\Kinetix\Forms\Components\TimePicker;
use Happones\Kinetix\Tests\TestCase;

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

    public function test_pickers_default_their_timezone_to_the_app_timezone(): void
    {
        config()->set('app.timezone', 'America/Mexico_City');

        $this->assertSame(
            'America/Mexico_City',
            DatePicker::make('published_at')->toData('create')?->timezone,
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
}
