<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Query\KinetixQuery;
use Happones\Kinetix\Query\SearchMode;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class SmThing extends Model
{
    protected $table = 'sm_things';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['qty' => 'integer', 'price' => 'decimal:2', 'seen_at' => 'datetime'];
}

class SmStamped extends Model
{
    protected $table = 'sm_things';

    protected $guarded = [];
}

class SmOwner extends Model
{
    protected $table = 'sm_owners';

    public $timestamps = false;

    public function things(): HasMany
    {
        return $this->hasMany(SmThing::class, 'team_id');
    }
}

class SmUuidThing extends Model
{
    use HasUuids;

    protected $table = 'sm_uuid_things';

    public $timestamps = false;

    protected $guarded = [];
}

class SmUlidThing extends Model
{
    use HasUlids;

    protected $table = 'sm_ulid_things';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * The search modes: how a term is compared, the type guard of Exact, the
 * fail-closed behaviour, and that nothing escapes the caller's constraints.
 */
class SearchModeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('sm_things', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('team_id')->default(1);
            $table->string('name');
            $table->integer('qty')->default(0);
            $table->decimal('price', 8, 2)->default(0);
            $table->dateTime('seen_at')->nullable();
        });

        SmThing::create(['name' => 'Alpha 100%', 'qty' => 5, 'team_id' => 1]);
        SmThing::create(['name' => 'Beta_x', 'qty' => 7, 'team_id' => 1]);
        SmThing::create(['name' => 'Alphabet', 'qty' => 5, 'team_id' => 2]);
    }

    /** @return Builder<SmThing> */
    private function on(string $driver, string $model = SmThing::class): Builder
    {
        config(["database.connections.fake_{$driver}" => [
            'driver'   => $driver,
            'host'     => '127.0.0.1',
            'database' => 'fake',
            'username' => 'x',
            'password' => 'x',
        ]]);

        return (new $model)->setConnection("fake_{$driver}")->newQuery();
    }

    /** @return list<string> */
    private function names(Builder $query): array
    {
        return $query->orderBy('id')->pluck('name')->all();
    }

    public function test_a_plain_list_still_means_contains(): void
    {
        $query = KinetixQuery::search(SmThing::query(), 'lph', ['name']);

        $this->assertStringContainsString("like ? escape '!'", $query->toSql());
        $this->assertSame(['%lph%'], $query->getBindings());
    }

    public function test_prefix_anchors_the_start_and_escapes_wildcards(): void
    {
        $query = KinetixQuery::search(SmThing::query(), '100%_', ['name' => SearchMode::Prefix]);

        $this->assertSame(['100!%!_%'], $query->getBindings());
        $this->assertSame(['Alpha 100%', 'Alphabet'], $this->names(KinetixQuery::search(SmThing::query(), 'Alpha', ['name' => SearchMode::Prefix])));
        $this->assertSame([], $this->names(KinetixQuery::search(SmThing::query(), 'lpha', ['name' => SearchMode::Prefix])));
    }

    public function test_the_default_argument_applies_to_list_entries_only(): void
    {
        $query = KinetixQuery::search(SmThing::query(), 'Al', ['name', 'qty' => SearchMode::Exact], SearchMode::Prefix);

        $this->assertSame(['Al%'], array_slice($query->getBindings(), 0, 1));
    }

    public function test_exact_matches_the_whole_value_only(): void
    {
        $this->assertSame(['Beta_x'], $this->names(KinetixQuery::search(SmThing::query(), 'Beta_x', ['name' => SearchMode::Exact])));
        $this->assertSame([], $this->names(KinetixQuery::search(SmThing::query(), 'Beta', ['name' => SearchMode::Exact])));
    }

    public function test_exact_on_an_integer_key_skips_a_term_that_cannot_match(): void
    {
        $sql = KinetixQuery::search(SmThing::query(), 'abc', ['id' => SearchMode::Exact])->toSql();

        $this->assertStringContainsString('1 = 0', $sql);
        $this->assertSame([], $this->names(KinetixQuery::search(SmThing::query(), 'abc', ['id' => SearchMode::Exact])));
        $this->assertSame(['Alpha 100%'], $this->names(KinetixQuery::search(SmThing::query(), '1', ['id' => SearchMode::Exact])));
        // Out of range for a bigint: cannot be a key, so it never reaches the engine.
        $this->assertSame([], $this->names(KinetixQuery::search(SmThing::query(), str_repeat('9', 30), ['id' => SearchMode::Exact])));
    }

    public function test_exact_guards_cast_columns(): void
    {
        $this->assertSame([], $this->names(KinetixQuery::search(SmThing::query(), 'x', ['qty' => SearchMode::Exact])));
        $this->assertSame(['Alpha 100%', 'Alphabet'], $this->names(KinetixQuery::search(SmThing::query(), '5', ['qty' => SearchMode::Exact])));
        $this->assertStringContainsString('1 = 0', KinetixQuery::search(SmThing::query(), 'x', ['price' => SearchMode::Exact])->toSql());
        $this->assertStringContainsString('1 = 0', KinetixQuery::search(SmThing::query(), 'not a date', ['seen_at' => SearchMode::Exact])->toSql());
        $this->assertStringNotContainsString('1 = 0', KinetixQuery::search(SmThing::query(), '2026-01-02', ['seen_at' => SearchMode::Exact])->toSql());
    }

    public function test_a_skipped_column_leaves_the_others_searching(): void
    {
        $query = KinetixQuery::search(SmThing::query(), 'Beta_x', ['id' => SearchMode::Exact, 'name' => SearchMode::Exact]);

        $this->assertSame(['Beta_x'], $this->names($query));
        $this->assertStringNotContainsString('1 = 0', $query->toSql());
    }

    public function test_exact_validates_uuid_and_ulid_keys(): void
    {
        $uuid = KinetixQuery::search($this->on('sqlite', SmUuidThing::class), 'nope', ['id' => SearchMode::Exact])->toSql();
        $ok   = KinetixQuery::search($this->on('sqlite', SmUuidThing::class), '7f9c1e3a-5b2d-4a6e-8c1d-0123456789ab', ['id' => SearchMode::Exact])->toSql();
        $ulid = KinetixQuery::search($this->on('sqlite', SmUlidThing::class), 'nope', ['id' => SearchMode::Exact])->toSql();

        $this->assertStringContainsString('1 = 0', $uuid);
        $this->assertStringNotContainsString('1 = 0', $ok);
        $this->assertStringContainsString('1 = 0', $ulid);
    }

    public function test_no_columns_leaves_the_query_untouched(): void
    {
        $this->assertSame(SmThing::query()->toSql(), KinetixQuery::search(SmThing::query(), 'x', [])->toSql());
        $this->assertSame(SmThing::query()->toSql(), KinetixQuery::search(SmThing::query(), '  ', ['name'])->toSql());
    }

    public function test_full_text_falls_back_to_contains_on_sqlite(): void
    {
        $query = KinetixQuery::search(SmThing::query(), 'lph', ['name' => SearchMode::FullText]);

        $this->assertSame(['%lph%'], $query->getBindings());
    }

    public function test_full_text_uses_the_engine_on_mysql_and_postgres(): void
    {
        config(['kinetix.search.full_text.language' => 'spanish']);

        $mysql = KinetixQuery::search($this->on('mysql'), 'lph', ['name' => SearchMode::FullText])->toSql();
        $pg    = KinetixQuery::search($this->on('pgsql'), 'lph', ['name' => SearchMode::FullText])->toSql();

        $this->assertStringContainsString('match (`sm_things`.`name`) against (? in natural language mode)', $mysql);
        $this->assertStringContainsString("to_tsvector('spanish'", $pg);
        $this->assertStringContainsString("plainto_tsquery('spanish'", $pg);
    }

    public function test_postgres_casts_numeric_and_key_columns_but_not_text(): void
    {
        $key  = KinetixQuery::search($this->on('pgsql'), '1', ['id' => SearchMode::Contains])->toSql();
        $qty  = KinetixQuery::search($this->on('pgsql'), '5', ['qty' => SearchMode::Prefix])->toSql();
        $text = KinetixQuery::search($this->on('pgsql'), 'a', ['name' => SearchMode::Prefix])->toSql();

        $this->assertStringContainsString('cast("sm_things"."id" as text) like ?', $key);
        $this->assertStringContainsString('cast("sm_things"."qty" as text) like ?', $qty);
        $this->assertStringNotContainsString('cast(', $text);
    }

    public function test_the_cast_is_postgres_only(): void
    {
        $this->assertStringNotContainsString('cast(', KinetixQuery::search($this->on('mysql'), '1', ['id' => SearchMode::Contains])->toSql());
        $this->assertStringNotContainsString('cast(', KinetixQuery::search(SmThing::query(), '1', ['id' => SearchMode::Contains])->toSql());
    }

    public function test_a_using_closure_runs_inside_the_group_and_cannot_widen_the_scope(): void
    {
        $query = KinetixQuery::search(
            SmThing::query()->where('team_id', 1),
            'whatever',
            ['name' => fn (Builder $q, string $term) => $q->where('name', $term)->orWhere('id', '>', 0)],
        );

        $this->assertSame(['Alpha 100%', 'Beta_x'], $this->names($query));
    }

    public function test_a_using_closure_receives_the_trimmed_term(): void
    {
        $seen = null;

        KinetixQuery::search(SmThing::query(), '  hi  ', ['name' => function (Builder $q, string $term) use (&$seen): void {
            $seen = $term;
        }])->get();

        $this->assertSame('hi', $seen);
    }

    public function test_a_map_can_mix_modes_closures_and_default_entries(): void
    {
        $query = KinetixQuery::search(
            SmThing::query(),
            'Beta_x',
            ['name' => SearchMode::Exact, 'qty' => fn (Builder $q, string $t) => $q->where('qty', -1), 0 => 'name'],
        );

        $this->assertSame(['Beta_x'], $this->names($query));
    }

    public function test_the_mode_is_a_string_backed_enum(): void
    {
        $this->assertSame('full_text', SearchMode::FullText->value);
        $this->assertSame(SearchMode::Contains, SearchMode::tryFrom('contains'));
        $this->assertNull(SearchMode::tryFrom('regex'));
    }

    public function test_postgres_binds_an_integer_exact_term_as_bigint(): void
    {
        $pg    = KinetixQuery::search($this->on('pgsql'), '99999999999', ['id' => SearchMode::Exact]);
        $mysql = KinetixQuery::search($this->on('mysql'), '99999999999', ['id' => SearchMode::Exact]);

        $this->assertStringContainsString('= cast(? as bigint)', $pg->toSql());
        $this->assertSame([99999999999], $pg->getBindings());
        $this->assertStringNotContainsString('cast(', $mysql->toSql());
    }

    public function test_exact_dates_are_parsed_strictly(): void
    {
        foreach (['2026', 'monday', '+1 week', '2026-02-30', 'tomorrow', '2026-01-02T10:00'] as $term) {
            $this->assertStringContainsString('1 = 0', KinetixQuery::search(SmThing::query(), $term, ['seen_at' => SearchMode::Exact])->toSql(), $term);
        }

        $date = KinetixQuery::search(SmThing::query(), '2026-01-02', ['seen_at' => SearchMode::Exact]);
        $time = KinetixQuery::search(SmThing::query(), '2026-01-02 10:30', ['seen_at' => SearchMode::Exact]);

        $this->assertSame(['2026-01-02 00:00:00'], $date->getBindings());
        $this->assertSame(['2026-01-02 10:30:00'], $time->getBindings());
    }

    public function test_exact_on_a_date_only_column_takes_no_time_of_day(): void
    {
        $model = new class extends Model
        {
            protected $table = 'sm_things';

            protected $casts = ['born' => 'date'];
        };

        $this->assertStringContainsString('1 = 0', KinetixQuery::search($model::query(), '2026-01-02 10:30', ['born' => SearchMode::Exact])->toSql());
        $this->assertSame(['2026-01-02'], KinetixQuery::search($model::query(), '2026-01-02', ['born' => SearchMode::Exact])->getBindings());
    }

    public function test_exact_refuses_non_finite_numbers(): void
    {
        $this->assertStringContainsString('1 = 0', KinetixQuery::search(SmThing::query(), '1e999', ['price' => SearchMode::Exact])->toSql());
        $this->assertStringNotContainsString('1 = 0', KinetixQuery::search(SmThing::query(), '12.50', ['price' => SearchMode::Exact])->toSql());
    }

    public function test_timestamps_count_as_dates_on_postgres(): void
    {
        $sql = KinetixQuery::search($this->on('pgsql', SmStamped::class), '2026', ['created_at' => SearchMode::Contains])->toSql();

        $this->assertStringContainsString('cast("sm_things"."created_at" as text) like ?', $sql);
        $this->assertStringContainsString('1 = 0', KinetixQuery::search(SmStamped::query(), 'yesterday', ['updated_at' => SearchMode::Exact])->toSql());
    }

    public function test_a_using_closure_that_adds_no_where_matches_nothing(): void
    {
        $sql = KinetixQuery::search(SmThing::query(), 'x', ['name' => fn (Builder $q, string $t) => $q->orderBy('id')])->toSql();

        $this->assertStringContainsString('1 = 0', $sql);
        $this->assertSame([], SmThing::query()->tap(fn ($q) => KinetixQuery::search($q, 'x', ['name' => fn (Builder $q) => null]))->get()->all());
    }

    public function test_a_using_closure_that_adds_a_where_counts(): void
    {
        $sql = KinetixQuery::search(SmThing::query(), 'x', ['name' => fn (Builder $q, string $t) => $q->where('name', $t)])->toSql();

        $this->assertStringNotContainsString('1 = 0', $sql);
    }

    public function test_an_invalid_spec_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        KinetixQuery::search(SmThing::query(), 'x', ['name' => ['like']]);
    }

    public function test_an_empty_list_is_still_unchanged(): void
    {
        $this->assertSame(SmThing::query()->toSql(), KinetixQuery::search(SmThing::query(), 'x', [])->toSql());
    }

    public function test_a_column_qualified_with_the_models_own_table_is_guarded(): void
    {
        $this->assertStringContainsString('1 = 0', KinetixQuery::search(SmThing::query(), 'abc', ['sm_things.id' => SearchMode::Exact])->toSql());
        $this->assertSame(['Alpha 100%'], $this->names(KinetixQuery::search(SmThing::query(), '1', ['sm_things.id' => SearchMode::Exact])));
    }

    public function test_a_relation_column_is_guarded_by_the_related_model(): void
    {
        $skipped = KinetixQuery::search(SmOwner::query(), 'abc', ['things.id' => SearchMode::Exact])->toSql();
        $kept    = KinetixQuery::search(SmOwner::query(), '12', ['things.id' => SearchMode::Exact])->toSql();

        $this->assertStringContainsString('1 = 0', $skipped);
        $this->assertStringNotContainsString('exists', $skipped);
        $this->assertStringContainsString('exists', $kept);
        $this->assertStringNotContainsString('1 = 0', $kept);
    }

    public function test_an_associative_list_of_names_searches_its_values(): void
    {
        $query = KinetixQuery::search(SmThing::query(), 'Beta_x', ['n' => 'name']);

        $this->assertSame(['Beta_x'], $this->names($query));

        $prefix = KinetixQuery::search(SmThing::query(), 'Al', ['n' => 'name'], SearchMode::Prefix);

        $this->assertSame(['Al%'], $prefix->getBindings());
    }

    public function test_a_non_string_non_spec_value_still_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        KinetixQuery::search(SmThing::query(), 'x', ['name' => 5]);
    }

    public function test_non_key_integer_columns_are_bounded_to_int4_and_never_cast_to_bigint(): void
    {
        $this->assertStringContainsString('1 = 0', KinetixQuery::search(SmThing::query(), '99999999999', ['qty' => SearchMode::Exact])->toSql());
        $this->assertStringNotContainsString('1 = 0', KinetixQuery::search(SmThing::query(), '2147483647', ['qty' => SearchMode::Exact])->toSql());
        $this->assertStringContainsString('1 = 0', KinetixQuery::search(SmThing::query(), '2147483648', ['qty' => SearchMode::Exact])->toSql());

        $pg = KinetixQuery::search($this->on('pgsql'), '42', ['qty' => SearchMode::Exact])->toSql();

        $this->assertStringNotContainsString('cast(? as bigint)', $pg);
    }
}
