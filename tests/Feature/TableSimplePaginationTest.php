<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SimplePost extends Model
{
    protected $table = 'posts';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * A normal `paginate()` runs a `COUNT(*)` over the filtered query on every page
 * load. On a large table that count is the request. Simple mode trades the total
 * (and the last-page jump) for dropping it.
 */
class TableSimplePaginationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
        });

        foreach (range(1, 25) as $i) {
            SimplePost::create(['title' => "Post {$i}"]);
        }
    }

    private function table(bool $simple): Table
    {
        $table = Table::make(SimplePost::query())->columns([TextColumn::make('title')]);

        return $simple ? $table->simplePaginated() : $table;
    }

    public function test_simple_mode_runs_no_count_query(): void
    {
        $counts = [];

        foreach ([false, true] as $simple) {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->table($simple)->toArray();

            $counts[] = collect(DB::getQueryLog())
                ->filter(fn (array $q): bool => str_contains(strtolower((string) $q['query']), 'count(*)'))
                ->count();

            DB::disableQueryLog();
        }

        [$lengthAware, $simple] = $counts;

        $this->assertSame(1, $lengthAware, 'The default paginator should still count.');
        $this->assertSame(0, $simple, 'Simple pagination must not run a COUNT(*).');
    }

    public function test_it_reports_more_pages_without_a_total(): void
    {
        request()->merge(['perPage' => 10]);

        $pagination = $this->table(simple: true)->toArray()['pagination'];

        $this->assertNull($pagination['total']);
        $this->assertNull($pagination['lastPage']);
        $this->assertTrue($pagination['hasMore']);
        $this->assertSame(1, $pagination['currentPage']);
        $this->assertSame(1, $pagination['from']);
        $this->assertSame(10, $pagination['to']);
    }

    public function test_the_last_page_reports_no_more(): void
    {
        request()->merge(['perPage' => 10, 'page' => 3]);

        $pagination = $this->table(simple: true)->toArray()['pagination'];

        $this->assertFalse($pagination['hasMore']);
        $this->assertSame(3, $pagination['currentPage']);
    }

    public function test_it_still_returns_the_right_rows(): void
    {
        request()->merge(['perPage' => 10, 'page' => 2]);

        $data = $this->table(simple: true)->toArray();

        $this->assertCount(10, $data['records']);
        $this->assertSame('Post 11', $data['records'][0]['values']['title']);
    }

    public function test_the_default_paginator_keeps_its_full_payload(): void
    {
        request()->merge(['perPage' => 10]);

        $pagination = $this->table(simple: false)->toArray()['pagination'];

        $this->assertSame(25, $pagination['total']);
        $this->assertSame(3, $pagination['lastPage']);
        $this->assertTrue($pagination['hasMore']);
    }

    public function test_simple_mode_can_be_turned_back_off(): void
    {
        $pagination = Table::make(SimplePost::query())
            ->columns([TextColumn::make('title')])
            ->simplePaginated()
            ->simplePaginated(false)
            ->toArray()['pagination'];

        $this->assertNotNull($pagination['total']);
    }
}
