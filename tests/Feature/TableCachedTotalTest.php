<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Impersonation\KinetixImpersonation;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CtTeam extends Model
{
    protected $table = 'ct_teams';

    public $timestamps = false;

    protected $guarded = [];
}

class CtUser extends Authenticatable
{
    protected $table = 'ct_users';

    public $timestamps = false;

    protected $guarded = [];

    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(CtTeam::class, 'current_team_id');
    }
}

class CtRow extends Model
{
    protected $table = 'ct_rows';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * Table::cachedTotal() remembers a length-aware total per query, team and user.
 */
class TableCachedTotalTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('ct_teams', function (Blueprint $table) {
            $table->increments('id');
        });

        Schema::create('ct_users', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('current_team_id')->nullable();
        });

        Schema::create('ct_rows', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->string('kind')->default('a');
        });

        foreach (range(1, 25) as $i) {
            CtRow::create(['title' => "Row {$i}", 'kind' => $i % 2 === 0 ? 'a' : 'b']);
        }
    }

    /**
     * @param  Builder<CtRow>|null  $query
     * @return array<string, mixed>
     */
    private function render(bool|int $ttl = true, int $minimum = 1, ?Builder $query = null, ?callable $tap = null): array
    {
        $table = Table::make($query ?? CtRow::query())
            ->columns([TextColumn::make('title')->searchable()->sortable()])
            ->cachedTotal($ttl, $minimum);

        if ($tap !== null) {
            $tap($table);
        }

        return $table->toArray();
    }

    private function counts(callable $fn): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $fn();

        $n = collect(DB::getQueryLog())
            ->filter(fn (array $q): bool => str_contains(strtolower((string) $q['query']), 'count(*)'))
            ->count();

        DB::disableQueryLog();

        return $n;
    }

    private function actAsTeam(CtTeam $team): CtUser
    {
        $user = CtUser::create(['current_team_id' => $team->id]);
        $this->actingAs($user);

        return $user;
    }

    public function test_the_second_render_runs_no_count_and_is_marked_approximate(): void
    {
        $this->assertSame(1, $this->counts(fn () => $first = $this->render()));
        $this->assertSame(0, $this->counts(function () use (&$second): void {
            $second = $this->render();
        }));

        $this->assertSame(25, $second['pagination']['total']);
        $this->assertTrue($second['pagination']['totalIsApproximate']);
    }

    public function test_the_first_render_is_exact(): void
    {
        $this->assertFalse($this->render()['pagination']['totalIsApproximate']);
    }

    public function test_paging_and_sorting_reuse_the_total(): void
    {
        $this->render();

        request()->merge(['page' => 2, 'sort' => 'title', 'direction' => 'desc']);

        $this->assertSame(0, $this->counts(function () use (&$data): void {
            $data = $this->render();
        }));
        $this->assertSame(25, $data['pagination']['total']);
        $this->assertSame(2, $data['pagination']['currentPage']);
    }

    public function test_a_different_filter_or_search_is_a_different_total(): void
    {
        $this->render();

        request()->merge(['search' => 'Row 1']);

        $this->assertSame(1, $this->counts(function () use (&$data): void {
            $data = $this->render();
        }));
        $this->assertFalse($data['pagination']['totalIsApproximate']);
    }

    public function test_totals_below_the_minimum_are_never_cached(): void
    {
        $this->render(true, 100);

        $this->assertSame(1, $this->counts(fn () => $this->render(true, 100)));
    }

    public function test_a_total_of_zero_is_never_cached_even_with_a_zero_minimum(): void
    {
        request()->merge(['search' => 'Brand new']);
        $this->render(true, 0);

        CtRow::create(['title' => 'Brand new row']);

        $data = $this->render(true, 0);

        $this->assertSame(1, $data['pagination']['total']);
        $this->assertCount(1, $data['records']);
    }

    public function test_it_is_off_unless_asked_for(): void
    {
        $this->render(false);

        $this->assertSame(1, $this->counts(fn () => $this->render(false)));
    }

    public function test_the_config_supplies_the_minimum_when_none_is_given(): void
    {
        config(['kinetix.tables.cached_total.minimum' => 1000]);

        $table = fn (): Table => Table::make(CtRow::query())->columns([TextColumn::make('title')])->cachedTotal();

        $table()->toArray();

        $this->assertSame(1, $this->counts(fn () => $table()->toArray()));
    }

    public function test_the_ttl_is_honoured(): void
    {
        $this->render(30);

        $keys = array_filter(
            array_keys($this->arrayStoreEntries()),
            fn (string $key): bool => str_starts_with($key, 'kinetix:table-total:v1:'),
        );

        $this->assertCount(1, $keys);
    }

    public function test_a_page_that_comes_back_empty_under_a_cached_total_recounts_once(): void
    {
        $this->render();

        CtRow::query()->where('id', '>', 5)->delete();

        request()->merge(['page' => 2, 'perPage' => 5]);

        $data = null;
        $n    = $this->counts(function () use (&$data): void {
            $data = $this->render();
        });

        $this->assertSame(1, $n);
        $this->assertSame(5, $data['pagination']['total']);
        $this->assertFalse($data['pagination']['totalIsApproximate']);
    }

    public function test_simple_and_cursor_modes_ignore_it(): void
    {
        $simple = fn (): array => $this->render(tap: fn (Table $t) => $t->simplePaginated());
        $cursor = fn (): array => $this->render(tap: fn (Table $t) => $t->cursorPaginated());

        foreach ([$simple, $cursor] as $render) {
            $data = $render();

            $this->assertNull($data['pagination']['total']);
            $this->assertFalse($data['pagination']['totalIsApproximate']);
        }

        $this->assertSame([], array_filter(
            array_keys($this->arrayStoreEntries()),
            fn (string $key): bool => str_starts_with($key, 'kinetix:table-total:v1:'),
        ));
    }

    public function test_the_paginate_helper_uses_the_cache_too(): void
    {
        $table = Table::make(CtRow::query())->columns([TextColumn::make('title')])->cachedTotal(true, 1);

        $table->paginate($table->applyTo(CtRow::query()));

        $this->assertSame(0, $this->counts(function () use ($table, &$page): void {
            $page = $table->paginate($table->applyTo(CtRow::query()));
        }));
        $this->assertSame(25, $page->total());
    }

    public function test_different_users_do_not_share_a_total(): void
    {
        $team = CtTeam::create();

        $this->actAsTeam($team);
        $this->render();

        $this->actAsTeam($team);

        $this->assertSame(1, $this->counts(fn () => $this->render()));
    }

    public function test_the_same_user_hits_and_a_guest_is_separate(): void
    {
        $this->actAsTeam(CtTeam::create());
        $this->render();

        $this->assertSame(0, $this->counts(fn () => $this->render()));

        auth()->logout();

        $this->assertSame(1, $this->counts(fn () => $this->render()));
    }

    public function test_an_impersonated_session_does_not_reuse_the_real_users_total(): void
    {
        $user = $this->actAsTeam(CtTeam::create());
        $this->render();

        session()->put('kinetix_impersonator_id', 99);
        $this->assertNotNull(KinetixImpersonation::impersonatorId(), 'The impersonation fixture must be active.');
        $this->actingAs($user);

        $this->assertSame(1, $this->counts(fn () => $this->render()));
    }

    public function test_teams_off_still_caches_for_everyone_the_same_way(): void
    {
        config(['kinetix.teams' => false]);

        $this->render();

        $this->assertSame(0, $this->counts(fn () => $this->render()));
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayStoreEntries(): array
    {
        $store = Cache::getStore();
        $ref   = new \ReflectionProperty($store, 'storage');

        return $ref->getValue($store);
    }
}
