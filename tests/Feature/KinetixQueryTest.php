<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Query\KinetixQuery;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class QueryAuthor extends Model
{
    protected $table = 'authors';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return HasMany<QueryPost, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(QueryPost::class, 'author_id');
    }
}

class QueryPost extends Model
{
    protected $table = 'posts';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return BelongsTo<QueryAuthor, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(QueryAuthor::class, 'author_id');
    }
}

/**
 * The primitives every Kinetix reader shares. They were reimplemented five
 * times, each subtly different — one escaped nothing, another let its `orWhere`s
 * escape the surrounding `where()`.
 */
class KinetixQueryTest extends TestCase
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
            $table->unsignedInteger('author_id')->nullable();
            $table->unsignedInteger('team_id')->nullable();
            $table->string('title');
            $table->string('meta')->nullable();
        });

        $ada   = QueryAuthor::create(['name' => 'Ada']);
        $grace = QueryAuthor::create(['name' => 'Grace']);

        QueryPost::create(['author_id' => $ada->id, 'team_id' => 1, 'title' => 'Discount 100% off']);
        QueryPost::create(['author_id' => $grace->id, 'team_id' => 1, 'title' => 'Compilers']);
        QueryPost::create(['author_id' => $grace->id, 'team_id' => 2, 'title' => 'Other tenant']);
    }

    public function test_like_wildcards_in_user_input_are_escaped(): void
    {
        // Unescaped, `%` would match every row and scan the table.
        $titles = KinetixQuery::search(QueryPost::query(), '100%', ['title'])->pluck('title')->all();

        $this->assertSame(['Discount 100% off'], $titles);
    }

    public function test_an_underscore_is_not_a_single_character_wildcard(): void
    {
        QueryPost::create(['title' => 'a_b']);
        QueryPost::create(['title' => 'axb']);

        $titles = KinetixQuery::search(QueryPost::query(), 'a_b', ['title'])->pluck('title')->all();

        $this->assertSame(['a_b'], $titles);
    }

    public function test_the_search_is_grouped_so_it_cannot_widen_an_existing_filter(): void
    {
        // The critical property: OR terms must not escape the tenant filter.
        $rows = KinetixQuery::search(
            QueryPost::query()->where('team_id', 1),
            'Other',
            ['title'],
        )->get();

        $this->assertCount(0, $rows);
    }

    public function test_it_searches_across_a_relation_with_dot_notation(): void
    {
        $titles = KinetixQuery::search(QueryPost::query(), 'Ada', ['title', 'author.name'])
            ->pluck('title')
            ->all();

        $this->assertSame(['Discount 100% off'], $titles);
    }

    public function test_an_empty_term_or_no_columns_leaves_the_query_untouched(): void
    {
        $this->assertCount(3, KinetixQuery::search(QueryPost::query(), '   ', ['title'])->get());
        $this->assertCount(3, KinetixQuery::search(QueryPost::query(), 'Ada', [])->get());
    }

    public function test_dot_notation_columns_are_eager_loaded(): void
    {
        $query = KinetixQuery::eagerLoad(QueryPost::query(), ['title', 'author.name']);

        $this->assertArrayHasKey('author', $query->getEagerLoads());
    }

    public function test_eager_loading_removes_the_n_plus_1(): void
    {
        $columns = ['title', 'author.name'];

        // Baseline: one query per row to resolve `author.name`.
        $lazy = $this->countQueries(function () use ($columns): void {
            foreach (QueryPost::query()->get() as $post) {
                foreach ($columns as $column) {
                    data_get($post, $column);
                }
            }
        });

        $eager = $this->countQueries(function () use ($columns): void {
            foreach (KinetixQuery::eagerLoad(QueryPost::query(), $columns)->get() as $post) {
                foreach ($columns as $column) {
                    data_get($post, $column);
                }
            }
        });

        $this->assertSame(4, $lazy);   // 1 + one per row (3 posts)
        $this->assertSame(2, $eager);  // posts + authors
    }

    public function test_a_json_path_is_not_mistaken_for_a_relation(): void
    {
        // `meta.color` is a column path, not a relation — loading it would throw.
        $query = KinetixQuery::eagerLoad(QueryPost::query(), ['meta.color']);

        $this->assertSame([], $query->getEagerLoads());
    }

    public function test_an_already_loaded_relation_is_not_added_twice(): void
    {
        $query = KinetixQuery::eagerLoad(QueryPost::query()->with('author'), ['author.name']);

        $this->assertCount(1, $query->getEagerLoads());
    }

    public function test_it_sorts_through_a_belongs_to_relation(): void
    {
        $query = QueryPost::query();

        $this->assertTrue(KinetixQuery::sortByRelation($query, 'author.name', 'desc'));

        // A correlated subquery, not a join — so no row duplication.
        $this->assertSame(3, $query->count());
        $this->assertSame('Grace', $query->first()?->author?->name);
    }

    public function test_an_unsupported_relation_is_skipped_rather_than_guessed(): void
    {
        $this->assertFalse(KinetixQuery::sortByRelation(QueryAuthor::query(), 'posts.title', 'asc'));
        $this->assertFalse(KinetixQuery::sortByRelation(QueryPost::query(), 'nope.title', 'asc'));
        $this->assertFalse(KinetixQuery::sortByRelation(QueryPost::query(), 'author.company.name', 'asc'));
    }

    public function test_the_direction_is_normalized_from_untrusted_input(): void
    {
        $this->assertSame('desc', KinetixQuery::direction('DESC'));
        $this->assertSame('asc', KinetixQuery::direction('asc'));
        $this->assertSame('asc', KinetixQuery::direction('; drop table posts'));
        $this->assertSame('asc', KinetixQuery::direction(null));
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
