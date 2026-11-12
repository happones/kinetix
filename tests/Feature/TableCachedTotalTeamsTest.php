<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * With teams on, a cached total is bound to the resolved team: another team
 * recounts, and no resolvable team means the cache is neither read nor written.
 */
class TableCachedTotalTeamsTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('kinetix.teams', true);
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
            CtRow::create(['title' => "Row {$i}"]);
        }
    }

    private function render(): void
    {
        Table::make(CtRow::query())
            ->columns([TextColumn::make('title')])
            ->cachedTotal(true, 1)
            ->toArray();
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

    private function actAs(?CtTeam $team): CtUser
    {
        $user = CtUser::create(['current_team_id' => $team?->id]);
        $this->actingAs($user);

        return $user;
    }

    public function test_team_b_never_gets_team_as_total(): void
    {
        $a    = CtTeam::create();
        $b    = CtTeam::create();
        $user = $this->actAs($a);

        $this->render();
        $this->assertSame(0, $this->counts(fn () => $this->render()), 'Same team, same user: served from cache.');

        $user->update(['current_team_id' => $b->id]);
        $user->unsetRelation('currentTeam');

        $this->assertSame(1, $this->counts(fn () => $this->render()), 'Another team must recount.');
    }

    public function test_with_teams_on_and_no_team_the_cache_is_never_touched(): void
    {
        $this->actAs(null);

        Cache::spy();

        $this->render();
        $this->render();

        Cache::shouldNotHaveReceived('store');
        Cache::shouldNotHaveReceived('get');
        Cache::shouldNotHaveReceived('put');
    }

    public function test_with_teams_on_and_no_team_every_render_counts(): void
    {
        $this->actAs(null);

        $this->assertSame(1, $this->counts(fn () => $this->render()));
        $this->assertSame(1, $this->counts(fn () => $this->render()));
    }
}
