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

    public function test_maps_events_to_iso_dates_with_title_color_url(): void
    {
        $data = Calendar::make(CalendarEvent::query())
            ->dateColumn('starts_at')
            ->endColumn('ends_at')
            ->title('name')
            ->color('color')
            ->url(fn (CalendarEvent $e) => "/events/{$e->id}")
            ->heading('Schedule')
            ->toData();

        $this->assertSame('Schedule', $data->heading);
        $this->assertCount(2, $data->events);

        $launch = $data->events[0];
        $this->assertSame('Launch', $launch->title);
        $this->assertSame('2026-06-15', $launch->start);
        $this->assertNull($launch->end);
        $this->assertSame('#22c55e', $launch->color);
        $this->assertSame('/events/1', $launch->url);

        $sprint = $data->events[1];
        $this->assertSame('2026-06-20', $sprint->start);
        $this->assertSame('2026-06-24', $sprint->end);
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
}
