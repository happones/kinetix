<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Calendar\Calendar;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CalendarEvent extends Model
{
    protected $table = 'events';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}

class CalendarTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('events', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->string('color')->nullable();
        });

        CalendarEvent::create(['name' => 'Launch', 'starts_at' => '2026-06-15 09:00:00', 'color' => '#22c55e']);
        CalendarEvent::create(['name' => 'Sprint', 'starts_at' => '2026-06-20 00:00:00', 'ends_at' => '2026-06-24 00:00:00']);
    }

    public function test_maps_events_to_iso_datetimes_with_title_color_url(): void
    {
        $data = Calendar::make(CalendarEvent::query())
            ->dateColumn('starts_at')
            ->endColumn('ends_at')
            ->title('name')
            ->color('color')
            ->url(fn (CalendarEvent $e) => "/events/{$e->id}")
            ->heading('Schedule')
            ->timezone('UTC')
            ->toData();

        $this->assertSame('Schedule', $data->heading);
        $this->assertSame('UTC', $data->timezone);
        $this->assertCount(2, $data->events);

        $launch = $data->events[0];
        $this->assertSame('Launch', $launch->title);
        $this->assertSame('2026-06-15T09:00:00+00:00', $launch->start);
        $this->assertNull($launch->end);
        $this->assertFalse($launch->allDay);
        $this->assertSame('#22c55e', $launch->color);
        $this->assertSame('/events/1', $launch->url);

        $sprint = $data->events[1];
        $this->assertSame('2026-06-20T00:00:00+00:00', $sprint->start);
        $this->assertSame('2026-06-24T00:00:00+00:00', $sprint->end);
        $this->assertTrue($sprint->allDay);
    }

    public function test_respects_the_query_scope(): void
    {
        $data = Calendar::make(CalendarEvent::query())
            ->dateColumn('starts_at')
            ->title('name')
            ->query(fn ($q) => $q->where('name', 'Launch'))
            ->toData();

        $this->assertCount(1, $data->events);
        $this->assertSame('Launch', $data->events[0]->title);
    }

    public function test_defaults_the_timezone_to_the_app_timezone(): void
    {
        $data = Calendar::make(CalendarEvent::query())
            ->dateColumn('starts_at')
            ->title('name')
            ->toData();

        $this->assertSame(config('app.timezone'), $data->timezone);
    }

    public function test_timezone_shifts_the_serialized_instant(): void
    {
        $data = Calendar::make(CalendarEvent::query())
            ->dateColumn('starts_at')
            ->title('name')
            ->query(fn ($q) => $q->where('name', 'Launch'))
            ->timezone('America/Mexico_City')
            ->toData();

        $this->assertSame('America/Mexico_City', $data->timezone);
        // 09:00 UTC (the column has no tz suffix, so Carbon parses it in the
        // app's configured timezone — UTC in tests) shifts to 03:00 CST/CDT.
        $this->assertStringStartsWith('2026-06-15T03:00:00', $data->events[0]->start);
    }

    public function test_timezone_accepts_a_closure(): void
    {
        $data = Calendar::make(CalendarEvent::query())
            ->dateColumn('starts_at')
            ->title('name')
            ->query(fn ($q) => $q->where('name', 'Launch'))
            ->timezone(fn () => 'Asia/Tokyo')
            ->toData();

        $this->assertSame('Asia/Tokyo', $data->timezone);
    }

    public function test_description_resolves_from_an_attribute_or_closure(): void
    {
        $data = Calendar::make(CalendarEvent::query())
            ->dateColumn('starts_at')
            ->title('name')
            ->description(fn (CalendarEvent $e) => "Details for {$e->name}")
            ->query(fn ($q) => $q->where('name', 'Launch'))
            ->toData();

        $this->assertSame('Details for Launch', $data->events[0]->description);
    }
}
