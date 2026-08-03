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
}
