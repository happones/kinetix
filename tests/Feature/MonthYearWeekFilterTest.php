<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tables\Filters\MonthFilter;
use Happones\Kinetix\Tables\Filters\WeekFilter;
use Happones\Kinetix\Tables\Filters\YearFilter;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class DatedEvent extends Model
{
    protected $table = 'dated_events';

    public $timestamps = false;

    protected $guarded = [];
}

class MonthYearWeekFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('dated_events', function (Blueprint $table) {
            $table->increments('id');
            $table->date('happened_at');
        });

        foreach (['2026-06-15', '2026-01-10', '2025-06-15'] as $d) {
            DatedEvent::create(['happened_at' => $d]);
        }
    }

    public function test_month_filter_matches_year_and_month(): void
    {
        $q = DatedEvent::query();
        MonthFilter::make('happened_at')->apply($q, '2026-06');

        $this->assertSame(['2026-06-15'], $q->pluck('happened_at')->all());
    }

    public function test_year_filter_matches_the_year(): void
    {
        $q = DatedEvent::query();
        YearFilter::make('happened_at')->apply($q, '2026');

        $this->assertEqualsCanonicalizing(['2026-06-15', '2026-01-10'], $q->pluck('happened_at')->all());
    }

    public function test_week_filter_matches_the_iso_week(): void
    {
        // Derive the ISO week string for 2026-06-15 (native week-input format).
        $week = Carbon::parse('2026-06-15')->isoFormat('GGGG-[W]WW');

        $q = DatedEvent::query();
        WeekFilter::make('happened_at')->apply($q, $week);

        $this->assertSame(['2026-06-15'], $q->pluck('happened_at')->all());
    }

    public function test_blank_values_are_ignored(): void
    {
        $q = DatedEvent::query();
        MonthFilter::make('happened_at')->apply($q, '');
        YearFilter::make('happened_at')->apply($q, null);

        $this->assertCount(3, $q->get());
    }
}
