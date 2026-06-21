<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Tables\Filters\DateFilter;
use Happones\Kinetix\Tables\Filters\DateRangeFilter;
use Happones\Kinetix\Tables\Filters\DateTimeFilter;
use Happones\Kinetix\Tables\Filters\MultiSelectFilter;
use Happones\Kinetix\Tables\Filters\NumberRangeFilter;
use Happones\Kinetix\Tables\Filters\TernaryFilter;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class FiltItem extends Model
{
    protected $table = 'filt_items';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['active' => 'bool'];
}

class FiltersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('filt_items', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('status');
            $table->integer('score');
            $table->date('published_at');
            $table->dateTime('logged_at');
            $table->boolean('active');
        });

        FiltItem::insert([
            ['name' => 'A', 'status' => 'draft', 'score' => 10, 'published_at' => '2026-01-10', 'logged_at' => '2026-01-10 08:00:00', 'active' => true],
            ['name' => 'B', 'status' => 'published', 'score' => 50, 'published_at' => '2026-03-15', 'logged_at' => '2026-03-15 14:30:00', 'active' => false],
            ['name' => 'C', 'status' => 'archived', 'score' => 90, 'published_at' => '2026-06-20', 'logged_at' => '2026-06-20 23:00:00', 'active' => true],
        ]);
    }

    public function test_ternary_filter_true_and_false(): void
    {
        $true = FiltItem::query();
        TernaryFilter::make('active')->apply($true, '1');
        $this->assertSame(['A', 'C'], $true->orderBy('name')->pluck('name')->all());

        $false = FiltItem::query();
        TernaryFilter::make('active')->apply($false, '0');
        $this->assertSame(['B'], $false->pluck('name')->all());
    }

    public function test_ternary_filter_serializes_labelled_options(): void
    {
        $data = TernaryFilter::make('active')->trueLabel('Active')->falseLabel('Inactive')->toData();

        $this->assertSame('ternary', $data->type);
        $this->assertSame(['1' => 'Active', '0' => 'Inactive'], $data->options);
    }

    public function test_date_range_filter_between_bounds(): void
    {
        $query = FiltItem::query();
        DateRangeFilter::make('published_at')->apply($query, ['from' => '2026-02-01', 'to' => '2026-05-01']);

        $this->assertSame(['B'], $query->pluck('name')->all());
    }

    public function test_date_range_filter_open_ended(): void
    {
        $query = FiltItem::query();
        DateRangeFilter::make('published_at')->apply($query, ['from' => '2026-03-15', 'to' => null]);

        $this->assertSame(['B', 'C'], $query->orderBy('name')->pluck('name')->all());
    }

    public function test_number_range_filter(): void
    {
        $query = FiltItem::query();
        NumberRangeFilter::make('score')->apply($query, ['min' => 20, 'max' => 80]);

        $this->assertSame(['B'], $query->pluck('name')->all());
    }

    public function test_multi_select_filter_uses_where_in(): void
    {
        $query = FiltItem::query();
        MultiSelectFilter::make('status')->apply($query, ['draft', 'archived']);

        $this->assertSame(['A', 'C'], $query->orderBy('name')->pluck('name')->all());
    }

    public function test_multi_select_empty_selection_is_a_noop(): void
    {
        $query = FiltItem::query();
        MultiSelectFilter::make('status')->apply($query, []);

        $this->assertSame(3, $query->count());
    }

    public function test_multi_select_serializes_enum_options(): void
    {
        $data = MultiSelectFilter::make('status')
            ->options(['draft' => 'Draft', 'published' => 'Published'])
            ->toData();

        $this->assertSame('multi-select', $data->type);
        $this->assertSame(['draft' => 'Draft', 'published' => 'Published'], $data->options);
    }

    public function test_single_date_filter_matches_exact_day_by_default(): void
    {
        $query = FiltItem::query();
        DateFilter::make('published_at')->apply($query, '2026-03-15');

        $this->assertSame(['B'], $query->pluck('name')->all());
    }

    public function test_single_date_filter_honours_operator(): void
    {
        $query = FiltItem::query();
        DateFilter::make('published_at')->operator('>=')->apply($query, '2026-03-15');

        $this->assertSame(['B', 'C'], $query->orderBy('name')->pluck('name')->all());
    }

    public function test_datetime_filter_defaults_to_since(): void
    {
        $query = FiltItem::query();
        // Default operator '>=' and datetime-local "T" separator normalization.
        DateTimeFilter::make('logged_at')->apply($query, '2026-03-15T00:00');

        $this->assertSame(['B', 'C'], $query->orderBy('name')->pluck('name')->all());
    }

    public function test_date_range_calendar_variant_serializes_flag(): void
    {
        $native = DateRangeFilter::make('published_at')->toData();
        $calendar = DateRangeFilter::make('published_at')->calendar()->toData();

        $this->assertFalse($native->useCalendar);
        $this->assertTrue($calendar->useCalendar);
        $this->assertSame('date-range', $calendar->type);
        $this->assertSame(1, $calendar->numberOfMonths);
        $this->assertNull($calendar->locale);
    }

    public function test_date_range_calendar_options_serialize(): void
    {
        $data = DateRangeFilter::make('published_at')
            ->months(2)
            ->locale('es')
            ->weekdayFormat('short')
            ->fixedWeeks()
            ->minValue('2026-01-01')
            ->maxValue('2026-12-31')
            ->toData();

        // Any calendar option implies the calendar variant.
        $this->assertTrue($data->useCalendar);
        $this->assertSame(2, $data->numberOfMonths);
        $this->assertSame('es', $data->locale);
        $this->assertSame('short', $data->weekdayFormat);
        $this->assertTrue($data->fixedWeeks);
        $this->assertSame('2026-01-01', $data->minValue);
        $this->assertSame('2026-12-31', $data->maxValue);
    }
}
