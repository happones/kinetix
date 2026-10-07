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

class EagerAuthor extends Model
{
    protected $table = 'authors';

    public $timestamps = false;

    protected $guarded = [];
}

class EagerPost extends Model
{
    protected $table = 'posts';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return BelongsTo<EagerAuthor, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(EagerAuthor::class, 'author_id');
    }
}

/**
 * `docs/tables.md` promises dot-notation columns render "without causing N+1
 * queries". Table never called `->with()`, so `data_get($record, 'author.name')`
 * lazy-loaded once per row and the promise only held when the caller remembered
 * to eager-load by hand.
 */
class TableEagerLoadTest extends TestCase
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
        });

        foreach (['Ada', 'Grace', 'Alan', 'Edsger', 'Barbara'] as $name) {
            $author = EagerAuthor::create(['name' => $name]);
            EagerPost::create(['author_id' => $author->id, 'title' => "By {$name}"]);
        }
    }

    public function test_a_relation_column_does_not_query_once_per_row(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $data = Table::make(EagerPost::query())
            ->columns([
                TextColumn::make('title'),
                TextColumn::make('author.name'),
            ])
            ->toArray();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // rows + count(*) for pagination + one eager load for the authors —
        // NOT one per row.
        $this->assertLessThanOrEqual(3, $queries, 'The relation column is still lazy-loading per row.');
        $this->assertCount(5, $data['records']);
        $this->assertSame('By Ada', $data['records'][0]['values']['title'] ?? null);
    }

    public function test_searching_a_relation_column_still_matches(): void
    {
        request()->merge(['search' => 'Grace']);

        $data = Table::make(EagerPost::query())
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('author.name')->searchable(),
            ])
            ->toArray();

        $this->assertCount(1, $data['records']);
    }

    public function test_a_search_term_with_a_wildcard_does_not_match_everything(): void
    {
        request()->merge(['search' => '%']);

        $data = Table::make(EagerPost::query())
            ->columns([TextColumn::make('title')->searchable()])
            ->toArray();

        $this->assertCount(0, $data['records']);
    }

    public function test_with_eager_loads_a_relation_the_column_scanner_cannot_see(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        // The relation is reached inside a state() closure, so it never appears
        // in a column name — the derived eager-load can't find it. with()
        // declares it explicitly.
        $data = Table::make(EagerPost::query())
            ->columns([
                TextColumn::make('title'),
                TextColumn::make('byline')
                    ->state(fn (EagerPost $post): string => (string) $post->author->name),
            ])
            ->with(['author'])
            ->toArray();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // rows + count(*) for pagination + one eager load for the authors —
        // NOT one per row (which would be 5 extra here).
        $this->assertLessThanOrEqual(3, $queries, 'with() did not eager-load the relation; it is lazy-loading per row.');
        $this->assertCount(5, $data['records']);
        $this->assertSame('Ada', $data['records'][0]['values']['byline'] ?? null);
    }

    public function test_a_computed_relation_column_without_with_still_n_plus_ones(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        // Same table as above but WITHOUT with() — proves the N+1 is real and
        // that with() is what fixes it, not some incidental eager-load.
        Table::make(EagerPost::query())
            ->columns([
                TextColumn::make('title'),
                TextColumn::make('byline')
                    ->state(fn (EagerPost $post): string => (string) $post->author->name),
            ])
            ->toArray();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // One query per author row on top of the base/count queries.
        $this->assertGreaterThan(3, $queries, 'Expected a lazy-load per row without with().');
    }

    public function test_with_de_dupes_and_accumulates_across_calls(): void
    {
        $table = Table::make(EagerPost::query())
            ->with(['author'])
            ->with(['author', 'author']);

        $reflection = new \ReflectionProperty(Table::class, 'with');

        $this->assertSame(['author'], $reflection->getValue($table));
    }

    public function test_with_is_a_no_op_when_empty(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $data = Table::make(EagerPost::query())
            ->columns([TextColumn::make('title')])
            ->with([])
            ->toArray();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // A plain column with an empty with() must not add an eager-load query.
        $this->assertLessThanOrEqual(2, $queries);
        $this->assertCount(5, $data['records']);
    }
}
