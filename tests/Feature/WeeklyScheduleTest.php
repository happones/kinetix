<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Carbon\Carbon;
use Happones\Kinetix\Forms\Components\BusinessHours;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Support\Casts\AsWeeklySchedule;
use Happones\Kinetix\Support\WeeklySchedule;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class WsVenue extends Model
{
    protected $table = 'ws_venues';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['hours' => AsWeeklySchedule::class];
}

class WeeklyScheduleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('ws_venues', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->text('hours')->nullable();
        });
    }

    public function test_from_array_normalizes_loose_input(): void
    {
        $schedule = WeeklySchedule::fromArray([
            'monday'   => ['enabled' => true, 'ranges' => [['start' => '09:00', 'end' => '17:00']]],
            'tuesday'  => ['enabled' => true, 'ranges' => [['start' => 'junk', 'end' => '17:00']]],
            'ghostday' => ['enabled' => true, 'ranges' => [['start' => '09:00', 'end' => '17:00']]],
        ]);

        $week = $schedule->effectiveSchedule();

        // All 7 days present; the invalid range was dropped, which disables
        // the day; unknown keys vanish.
        $this->assertSame(WeeklySchedule::DAYS, array_keys($week));
        $this->assertTrue($week['monday']['enabled']);
        $this->assertFalse($week['tuesday']['enabled']);
        $this->assertSame([], $week['tuesday']['ranges']);
        $this->assertFalse($week['sunday']['enabled']);
    }

    public function test_is_open_at_handles_plain_and_overnight_ranges(): void
    {
        $schedule = WeeklySchedule::fromArray([
            'monday' => ['enabled' => true, 'ranges' => [['start' => '09:00', 'end' => '17:00']]],
            'friday' => ['enabled' => true, 'ranges' => [['start' => '22:00', 'end' => '02:00']]],
        ]);

        // Plain range: [start, end) in the moment's own timezone.
        $this->assertTrue($schedule->isOpenAt(Carbon::parse('2024-01-01 09:00'))); // Monday
        $this->assertTrue($schedule->isOpenAt(Carbon::parse('2024-01-01 16:59')));
        $this->assertFalse($schedule->isOpenAt(Carbon::parse('2024-01-01 17:00')));
        $this->assertFalse($schedule->isOpenAt(Carbon::parse('2024-01-02 12:00'))); // Tuesday off

        // Overnight range: Friday 22:00 → Saturday 02:00.
        $this->assertTrue($schedule->isOpenAt(Carbon::parse('2024-01-05 23:30'))); // Friday night
        $this->assertTrue($schedule->isOpenAt(Carbon::parse('2024-01-06 01:30'))); // Saturday spillover
        $this->assertFalse($schedule->isOpenAt(Carbon::parse('2024-01-06 02:00')));
        $this->assertFalse($schedule->isOpenAt(Carbon::parse('2024-01-05 21:59')));
    }

    public function test_strict_validation_rejects_what_normalization_would_repair(): void
    {
        $this->assertTrue(WeeklySchedule::validate([
            'monday' => ['enabled' => true, 'ranges' => [['start' => '09:00', 'end' => '17:00']]],
        ]));

        // Bad time format.
        $this->assertFalse(WeeklySchedule::validate([
            'monday' => ['enabled' => true, 'ranges' => [['start' => '9am', 'end' => '17:00']]],
        ]));

        // start === end.
        $this->assertFalse(WeeklySchedule::validate([
            'monday' => ['enabled' => true, 'ranges' => [['start' => '09:00', 'end' => '09:00']]],
        ]));

        // Enabled day without ranges.
        $this->assertFalse(WeeklySchedule::validate([
            'monday' => ['enabled' => true, 'ranges' => []],
        ]));

        // Unknown day key.
        $this->assertFalse(WeeklySchedule::validate([
            'ghostday' => ['enabled' => false, 'ranges' => []],
        ]));

        $this->assertFalse(WeeklySchedule::validate('not-an-array'));
    }

    public function test_the_registered_validation_rule_gates_a_validator(): void
    {
        $passes = Validator::make(
            ['hours' => ['monday' => ['enabled' => true, 'ranges' => [['start' => '09:00', 'end' => '17:00']]]]],
            ['hours' => ['array', 'kinetix_weekly_schedule']],
        );
        $this->assertTrue($passes->passes());

        $fails = Validator::make(
            ['hours' => ['monday' => ['enabled' => true, 'ranges' => []]]],
            ['hours' => ['array', 'kinetix_weekly_schedule']],
        );
        $this->assertFalse($fails->passes());
        $this->assertArrayHasKey('hours', $fails->errors()->messages());
    }

    public function test_the_cast_round_trips_through_the_value_object(): void
    {
        $venue = WsVenue::create([
            'name'  => 'Gym',
            'hours' => ['monday' => ['enabled' => true, 'ranges' => [['start' => '06:00', 'end' => '22:00']]]],
        ]);

        $fresh = $venue->fresh();

        $this->assertInstanceOf(WeeklySchedule::class, $fresh->hours);
        $this->assertTrue($fresh->hours->isEnabled('monday'));
        $this->assertTrue($fresh->hours->isOpenAt(Carbon::parse('2024-01-01 07:00')));
        $this->assertFalse($fresh->hours->isOpenAt(Carbon::parse('2024-01-07 07:00'))); // Sunday

        // Null stays null.
        $empty = WsVenue::create(['name' => 'Closed']);
        $this->assertNull($empty->fresh()->hours);
    }

    public function test_the_business_hours_field_defaults_hydrates_and_validates(): void
    {
        $form = Form::make(new WsVenue)->schema([
            BusinessHours::make('hours'),
        ])->operation('create')->fill();

        // Default: Monday–Friday 09:00–17:00, weekend closed but seeded.
        $data = $form->toArray()['data']['hours'];
        $this->assertTrue($data['monday']['enabled']);
        $this->assertFalse($data['saturday']['enabled']);
        $this->assertSame('09:00', $data['saturday']['ranges'][0]['start']);

        // The cast VO hydrates back into the editor's array shape.
        $venue = WsVenue::create([
            'name'  => 'Gym',
            'hours' => ['tuesday' => ['enabled' => true, 'ranges' => [['start' => '10:00', 'end' => '14:00']]]],
        ])->fresh();

        $filled = Form::make($venue)->schema([BusinessHours::make('hours')])
            ->operation('edit')->fill($venue)->toArray()['data']['hours'];

        $this->assertIsArray($filled);
        $this->assertTrue($filled['tuesday']['enabled']);
        $this->assertFalse($filled['monday']['enabled']);

        // The field's seeded rule rejects malformed payloads.
        $invalid   = Form::make(new WsVenue)->schema([BusinessHours::make('hours')]);
        $validator = $invalid->makeValidator([
            'hours' => ['monday' => ['enabled' => true, 'ranges' => [['start' => '9am', 'end' => '17:00']]]],
        ]);

        $this->assertTrue($validator->fails());
    }
}
