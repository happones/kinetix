<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CursorAuthor extends Model
{
    protected $table = 'authors';

    public $timestamps = false;

    protected $guarded = [];
}

class CursorPost extends Model
{
    protected $table = 'posts';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return BelongsTo<CursorAuthor, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(CursorAuthor::class, 'author_id');
    }
}

/**
 * Cursor pagination seeks (`WHERE (sort, id) > (…)`) instead of using `OFFSET`,
 * so page 5,000 costs the same as page 1.
 *
 * The sharp edge it brings is silent: the cursor is built from the ORDER BY
 * columns, so a **non-unique** sort makes it step past the rest of a tied group
 * — rows vanish from the walk with no error anywhere. Kinetix appends the
 * primary key to make the ordering total.
 */
class TableCursorPaginationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('authors', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('author_id');
            $table->string('title');
            $table->string('status');
        });

        foreach (range(1, 6) as $i) {
            CursorAuthor::create(['id' => $i, 'name' => "Author {$i}"]);
            CursorPost::create([
                'author_id' => $i,
                'title'     => "Post {$i}",
                // Deliberately non-unique: 3 drafts, 3 live.
                'status' => $i % 2 === 1 ? 'draft' : 'live',
            ]);
        }
    }

    private function table(): Table
    {
        return Table::make(CursorPost::query())
            ->columns([
                TextColumn::make('title'),
                TextColumn::make('status')->sortable(),
                TextColumn::make('author.name')->sortable(),
            ])
            ->cursorPaginated();
    }

    /**
     * Walk every page and collect the ids actually visited.
     *
     * @return array{0: array<int, int>, 1: array<int, mixed>}
     */
    private function walk(int $perPage = 2): array
    {
        $seen       = [];
        $pagination = [];

        request()->merge(['perPage' => $perPage]);

        for ($i = 0; $i < 10; $i++) {
            $data       = $this->table()->toArray();
            $pagination = $data['pagination'];

            foreach ($data['records'] as $record) {
                $seen[] = (int) $record['id'];
            }

            if (! $pagination['hasMore']) {
                break;
            }

            request()->merge(['cursor' => $pagination['nextCursor']]);
        }

        return [$seen, $pagination];
    }

    public function test_a_non_unique_sort_still_visits_every_row(): void
    {
        // Without the primary-key tiebreaker this walk returns 4 of 6 rows.
        request()->merge(['sort' => 'status', 'direction' => 'asc']);

        [$seen] = $this->walk();

        sort($seen);

        $this->assertSame([1, 2, 3, 4, 5, 6], $seen, 'Cursor pagination skipped rows on a tied sort.');
    }

    public function test_it_visits_every_row_with_no_sort_at_all(): void
    {
        [$seen] = $this->walk();

        sort($seen);

        $this->assertSame([1, 2, 3, 4, 5, 6], $seen);
    }

    public function test_no_row_is_visited_twice(): void
    {
        request()->merge(['sort' => 'status', 'direction' => 'desc']);

        [$seen] = $this->walk();

        $this->assertSame(array_unique($seen), $seen, 'A row appeared on two pages.');
    }

    public function test_the_payload_carries_cursors_instead_of_page_numbers(): void
    {
        request()->merge(['perPage' => 2]);

        $pagination = $this->table()->toArray()['pagination'];

        $this->assertNull($pagination['currentPage']);
        $this->assertNull($pagination['total']);
        $this->assertNull($pagination['lastPage']);
        $this->assertNull($pagination['from']);
        $this->assertTrue($pagination['hasMore']);
        $this->assertTrue($pagination['onFirstPage']);
        $this->assertIsString($pagination['nextCursor']);
    }

    public function test_the_final_page_reports_no_more(): void
    {
        [, $pagination] = $this->walk();

        $this->assertFalse($pagination['hasMore']);
        $this->assertNull($pagination['nextCursor']);
    }

    public function test_it_runs_no_count_query(): void
    {
        request()->merge(['perPage' => 2]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->table()->toArray();

        $counted = collect(DB::getQueryLog())
            ->filter(fn (array $q): bool => str_contains(strtolower((string) $q['query']), 'count(*)'))
            ->count();

        DB::disableQueryLog();

        $this->assertSame(0, $counted);
    }

    public function test_it_seeks_instead_of_offsetting(): void
    {
        request()->merge(['perPage' => 2]);
        $first = $this->table()->toArray()['pagination'];

        request()->merge(['cursor' => $first['nextCursor']]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->table()->toArray();

        $sql = strtolower((string) (DB::getQueryLog()[0]['query'] ?? ''));
        DB::disableQueryLog();

        // The point of the mode: no OFFSET to walk past.
        $this->assertStringNotContainsString('offset', $sql);
    }

    /**
     * A relation sort orders by a correlated subquery, whose value the cursor
     * cannot encode — Laravel does not complain, it just paginates wrongly. The
     * table degrades to simple pagination for that request instead.
     */
    public function test_a_relation_sort_falls_back_to_simple_pagination(): void
    {
        request()->merge(['perPage' => 2, 'sort' => 'author.name', 'direction' => 'asc']);

        $pagination = $this->table()->toArray()['pagination'];

        $this->assertNull($pagination['nextCursor']);
        $this->assertSame(1, $pagination['currentPage']);
        $this->assertNull($pagination['total'], 'The fallback should stay count-free.');
        $this->assertTrue($pagination['hasMore']);
    }

    public function test_the_fallback_still_pages_correctly(): void
    {
        request()->merge(['perPage' => 4, 'sort' => 'author.name', 'direction' => 'asc', 'page' => 2]);

        $data = $this->table()->toArray();

        $this->assertCount(2, $data['records']);
        $this->assertFalse($data['pagination']['hasMore']);
    }

    public function test_cursor_mode_can_be_turned_back_off(): void
    {
        request()->merge(['perPage' => 2]);

        $pagination = Table::make(CursorPost::query())
            ->columns([TextColumn::make('title')])
            ->cursorPaginated()
            ->cursorPaginated(false)
            ->toArray()['pagination'];

        $this->assertSame(6, $pagination['total']);
        $this->assertSame(1, $pagination['currentPage']);
    }
}
