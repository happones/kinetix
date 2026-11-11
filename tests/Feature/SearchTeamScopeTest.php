<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Forms\Components\CheckboxList;
use Happones\Kinetix\Forms\Components\Select;
use Happones\Kinetix\Forms\SearchScope;
use Happones\Kinetix\Support\Concerns\ScopedToTeam;
use Happones\Kinetix\Support\DescriptorAudience;
use Happones\Kinetix\Support\KinetixStateReset;
use Happones\Kinetix\Support\SignedDescriptor;
use Happones\Kinetix\Tables\Filters\MultiSelectFilter;
use Happones\Kinetix\Tables\Filters\SelectFilter;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\PermissionServiceProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TsTeam extends Model
{
    protected $table = 'ts_teams';

    public $timestamps = false;

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}

class TsUser extends Authenticatable
{
    protected $table = 'ts_users';

    public $timestamps = false;

    protected $guarded = [];

    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(TsTeam::class, 'current_team_id');
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(TsTeam::class, 'ts_team_user', 'user_id', 'team_id');
    }
}

class TsCustomer extends Model
{
    protected $table = 'ts_customers';

    public $timestamps = false;

    protected $guarded = [];
}

class TsProject extends Model
{
    use ScopedToTeam;

    protected $table = 'ts_projects';

    public $timestamps = false;

    protected $guarded = [];

    public static function kinetixTeamModule(): string
    {
        return 'forms';
    }
}

class TsSharedProject extends TsProject
{
    public static function kinetixTeamRowsIncludeGlobal(): bool
    {
        return true;
    }
}

class TsCountry extends Model
{
    protected $table = 'ts_countries';

    public $timestamps = false;

    protected $guarded = [];
}

class TsOrder extends Model
{
    protected $table = 'ts_orders';

    public $timestamps = false;

    protected $guarded = [];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(TsCustomer::class, 'customer_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(TsProject::class, 'customer_id');
    }
}

class TsRole extends Model
{
    protected $table = 'ts_roles';

    public $timestamps = false;

    protected $guarded = [];

    public function orders(): HasMany
    {
        return $this->hasMany(TsOrder::class, 'customer_id');
    }
}

class TsOrderForRole extends Model
{
    protected $table = 'ts_orders';

    public $timestamps = false;

    protected $guarded = [];

    public function role(): BelongsTo
    {
        return $this->belongsTo(TsRole::class, 'customer_id');
    }
}

/**
 * Remote search is bounded to the active team by default, through the same
 * signed descriptor and membership checks as every other endpoint.
 */
class SearchTeamScopeTest extends TestCase
{
    private TsTeam $teamA;

    private TsTeam $teamB;

    /**
     * @param  Application              $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), PermissionServiceProvider::class];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('kinetix.teams', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        SearchScope::flush();

        Schema::create('ts_teams', function (Blueprint $table) {
            $table->increments('id');
            $table->string('slug');
        });

        Schema::create('ts_users', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('current_team_id');
        });

        Schema::create('ts_team_user', function (Blueprint $table) {
            $table->unsignedInteger('team_id');
            $table->unsignedInteger('user_id');
        });

        foreach (['ts_customers', 'ts_projects'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('team_id')->nullable();
                $table->string('name');
            });
        }

        Schema::create('ts_countries', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('ts_roles', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('team_id')->nullable();
            $table->string('name');
        });

        Schema::create('ts_orders', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('customer_id')->nullable();
        });

        $this->teamA = TsTeam::create(['slug' => 'team-a']);
        $this->teamB = TsTeam::create(['slug' => 'team-b']);
        TsTeam::create(['slug' => 'team-c']);

        $user = TsUser::create(['current_team_id' => $this->teamA->id]);
        $user->teams()->attach([$this->teamA->id, $this->teamB->id]);
        $this->actingAs($user);

        TsCustomer::create(['team_id' => $this->teamA->id, 'name' => 'Ana A']);
        TsCustomer::create(['team_id' => $this->teamB->id, 'name' => 'Ben B']);
        TsCustomer::create(['team_id' => null, 'name' => 'Nadia Nobody']);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function token(string $model = TsCustomer::class, array $extra = []): string
    {
        return SignedDescriptor::seal([
            'model'   => $model,
            'label'   => 'name',
            'columns' => ['name'],
            'value'   => 'id',
        ] + $extra, DescriptorAudience::Search);
    }

    /**
     * @return array<int, string>
     */
    private function search(string $token, string $team = 'team-a', string $q = ''): array
    {
        $response = $this->postJson(
            route('kinetix.forms.search', ['current_team' => $team]),
            ['token' => $token, 'q' => $q],
        )->assertOk();

        return array_values($response->json('options'));
    }

    public function test_a_team_id_model_without_the_trait_only_returns_the_active_team(): void
    {
        $this->assertSame(['Ana A'], $this->search($this->token()));
    }

    public function test_the_selected_label_lookup_is_bounded_to_the_team_too(): void
    {
        $foreign = TsCustomer::where('name', 'Ben B')->sole();
        $own     = TsCustomer::where('name', 'Ana A')->sole();

        $select = fn (int $id) => Select::make('customer_id')
            ->searchUsing(TsCustomer::class, 'name')
            ->toData('edit', new TsOrder(['customer_id' => $id]));

        $this->assertSame([], $select($foreign->id)->options);
        $this->assertSame([(string) $own->id => 'Ana A'], $select($own->id)->options);
    }

    public function test_the_filter_selected_label_lookup_is_bounded_to_the_team(): void
    {
        $foreign = TsCustomer::where('name', 'Ben B')->sole();
        $own     = TsCustomer::where('name', 'Ana A')->sole();

        foreach ([$foreign, $own] as $customer) {
            request()->merge(['filters' => ['customer_id' => $customer->id]]);

            $options = SelectFilter::make('customer_id')
                ->searchUsing(TsCustomer::class, 'name')
                ->toData()->options;

            $this->assertSame($customer->is($own) ? ['Ana A'] : [], array_values($options));
        }
    }

    public function test_a_scoped_to_team_model_uses_for_current_team(): void
    {
        TsProject::create(['team_id' => $this->teamA->id, 'name' => 'Alpha']);
        TsProject::create(['team_id' => $this->teamB->id, 'name' => 'Beta']);
        TsProject::create(['team_id' => null, 'name' => 'Global']);

        $this->assertSame(['Alpha'], $this->search($this->token(TsProject::class)));
        $this->assertEqualsCanonicalizing(['Alpha', 'Global'], $this->search($this->token(TsSharedProject::class)));
        $this->assertEqualsCanonicalizing(
            ['Alpha', 'Global'],
            $this->search($this->token(TsProject::class, ['team_scope_global' => true])),
        );
    }

    public function test_the_team_follows_the_url_segment(): void
    {
        $this->assertSame(['Ana A'], $this->search($this->token(), 'team-a'));
    }

    public function test_the_descriptor_opt_out_returns_every_team(): void
    {
        $token = $this->token(TsCustomer::class, ['team_scope' => false]);

        $this->assertEqualsCanonicalizing(['Ana A', 'Ben B', 'Nadia Nobody'], $this->search($token));
    }

    public function test_the_config_switch_returns_every_team(): void
    {
        config()->set('kinetix.search.team_scope', false);

        $this->assertCount(3, $this->search($this->token()));
    }

    public function test_without_team_scope_is_sealed_into_the_descriptor(): void
    {
        $select = Select::make('c')->searchUsing(TsCustomer::class)->withoutTeamScope()->toData('create');
        $filter = SelectFilter::make('c')->searchUsing(TsCustomer::class)->withoutTeamScope()->toData();
        $list   = CheckboxList::make('c')->searchUsing(TsCustomer::class)->withoutTeamScope()->toData('create');
        $plain  = Select::make('c')->searchUsing(TsCustomer::class)->toData('create');

        foreach ([$select->searchToken, $filter->searchToken, $list->searchToken] as $token) {
            $this->assertFalse(SignedDescriptor::open((string) $token)['team_scope']);
        }

        $this->assertArrayNotHasKey('team_scope', SignedDescriptor::open((string) $plain->searchToken));
    }

    public function test_an_opted_out_field_returns_every_team_end_to_end(): void
    {
        $token = Select::make('c')->searchUsing(TsCustomer::class)->withoutTeamScope()->toData('create')->searchToken;

        $this->assertCount(3, $this->search((string) $token));
    }

    public function test_an_explicit_search_scope_on_the_team_column_wins_and_is_not_doubled(): void
    {
        $token = $this->token(TsCustomer::class, ['scope' => ['team_id' => $this->teamB->id]]);

        $this->assertSame(['Ben B'], $this->search($token));

        DB::enableQueryLog();
        $this->search($token);
        $sql = collect(DB::getQueryLog())->pluck('query')->first(fn ($q) => str_contains($q, 'ts_customers') && str_contains($q, 'where'));

        $this->assertSame(1, substr_count((string) $sql, 'team_id'));
    }

    public function test_another_scope_column_does_not_disable_the_team_bound(): void
    {
        $this->assertSame([], $this->search($this->token(TsCustomer::class, ['scope' => ['name' => 'Ben B']])));
    }

    public function test_a_catalog_table_without_the_column_is_left_alone(): void
    {
        TsCountry::create(['name' => 'Mexico']);
        TsCountry::create(['name' => 'Chile']);

        $this->assertEqualsCanonicalizing(['Mexico', 'Chile'], $this->search($this->token(TsCountry::class)));
    }

    public function test_the_configured_team_column_is_used(): void
    {
        Schema::table('ts_countries', fn (Blueprint $t) => $t->unsignedInteger('company_id')->nullable());
        TsCountry::create(['name' => 'Mexico', 'company_id' => $this->teamA->id]);
        TsCountry::create(['name' => 'Chile', 'company_id' => $this->teamB->id]);
        config()->set('kinetix.search.team_column', 'company_id');
        SearchScope::flush();

        $this->assertSame(['Mexico'], $this->search($this->token(TsCountry::class)));
    }

    public function test_a_token_minted_in_one_team_is_rejected_under_another_teams_url(): void
    {
        $token = $this->token();

        $this->postJson(route('kinetix.forms.search', ['current_team' => 'team-b']), ['token' => $token, 'q' => ''])
            ->assertForbidden()
            ->assertJsonPath('code', 'descriptor_forbidden');
    }

    public function test_a_token_minted_for_another_user_is_rejected(): void
    {
        $token = $this->token();

        $other = TsUser::create(['current_team_id' => $this->teamA->id]);
        $other->teams()->attach($this->teamA->id);
        $this->actingAs($other);

        $this->postJson(route('kinetix.forms.search', ['current_team' => 'team-a']), ['token' => $token, 'q' => ''])
            ->assertForbidden();
    }

    public function test_a_foreign_team_segment_is_not_found_and_leaks_nothing(): void
    {
        $request = Request::create('/team-c/x', 'POST');
        $request->setUserResolver(fn () => auth()->user());
        $request->setRouteResolver(fn () => (new Route('POST', '{current_team}/x', []))->bind($request));
        app()->instance('request', $request);

        $this->expectException(NotFoundHttpException::class);

        SearchScope::apply(TsCustomer::query(), [])->get();
    }

    public function test_an_unresolvable_team_fails_closed_for_every_model_kind(): void
    {
        $request = Request::create('/x', 'POST');
        $this->actingAs(TsUser::create(['current_team_id' => 0]));
        app()->instance('request', $request);

        $names = SearchScope::apply(TsCustomer::query(), [])->pluck('name')->all();

        $this->assertSame([], $names);

        $this->assertSame([], SearchScope::apply(TsProject::query(), [])->get()->all());
    }

    public function test_teams_off_leaves_the_search_unbounded(): void
    {
        config()->set('kinetix.teams', false);

        $this->assertCount(3, SearchScope::apply(TsCustomer::query(), [])->get());
        TsProject::create(['team_id' => $this->teamB->id, 'name' => 'Beta']);
        TsProject::create(['team_id' => null, 'name' => 'Global']);
        $this->assertCount(2, SearchScope::apply(TsProject::query(), [])->get());
    }

    public function test_the_url_segment_beats_the_users_current_team(): void
    {
        $user = auth()->user();
        $user->update(['current_team_id' => $this->teamB->id]);
        $user->unsetRelation('currentTeam');
        $token = $this->token();

        $user->update(['current_team_id' => $this->teamA->id]);
        $user->unsetRelation('currentTeam');

        $this->assertSame(['Ben B'], $this->search($token, 'team-b'));
    }

    public function test_an_explicit_constraint_wins_on_a_scoped_to_team_model_too(): void
    {
        TsProject::create(['team_id' => $this->teamA->id, 'name' => 'Alpha']);
        TsProject::create(['team_id' => $this->teamB->id, 'name' => 'Beta']);
        TsProject::create(['team_id' => null, 'name' => 'Global']);

        $this->assertSame(['Beta'], $this->search($this->token(TsProject::class, ['scope' => ['team_id' => $this->teamB->id]])));
    }

    public function test_the_include_global_opt_in_keeps_shared_rows(): void
    {
        $select = Select::make('c')->searchUsing(TsCustomer::class)->teamScopeIncludesGlobal()->toData('create');
        $filter = SelectFilter::make('c')->searchUsing(TsCustomer::class)->teamScopeIncludesGlobal()->toData();

        foreach ([$select->searchToken, $filter->searchToken] as $token) {
            $this->assertEqualsCanonicalizing(['Ana A', 'Nadia Nobody'], $this->search((string) $token));
        }
    }

    public function test_the_role_model_keeps_global_roles_and_strict_validation_accepts_them(): void
    {
        config()->set('permission.models.role', TsRole::class);
        config()->set('permission.teams', true);

        $global = TsRole::create(['team_id' => null, 'name' => 'admin']);
        $own    = TsRole::create(['team_id' => $this->teamA->id, 'name' => 'Ops']);
        $other  = TsRole::create(['team_id' => $this->teamB->id, 'name' => 'Spy']);

        $this->assertEqualsCanonicalizing(['admin', 'Ops'], $this->search($this->token(TsRole::class)));

        $field = Select::make('customer_id')->forModel(TsOrderForRole::class)->relationship('role', 'name');
        $this->assertEqualsCanonicalizing(['admin', 'Ops'], array_values($field->toData('create')->options));

        $rule = $field->strictOptionRule();
        $this->assertTrue(Validator::make(['x' => $global->id], ['x' => [$rule]])->passes());
        $this->assertTrue(Validator::make(['x' => $own->id], ['x' => [$rule]])->passes());
        $this->assertFalse(Validator::make(['x' => $other->id], ['x' => [$rule]])->passes());
    }

    public function test_a_relationship_select_honors_its_search_scope_and_validation(): void
    {
        $b = TsCustomer::where('name', 'Ben B')->sole();

        $field = Select::make('customer_id')->forModel(TsOrder::class)->relationship('customer', 'name')->searchable()
            ->searchScope(['team_id' => $this->teamB->id]);

        $this->assertSame(['Ben B'], array_values($field->toData('create')->options));
        $this->assertTrue(Validator::make(['x' => $b->id], ['x' => [$field->strictOptionRule()]])->passes());

        $a = TsCustomer::where('name', 'Ana A')->sole();
        $this->assertFalse(Validator::make(['x' => $a->id], ['x' => [$field->strictOptionRule()]])->passes());
    }

    public function test_a_relationship_select_is_team_bound_without_declaring_anything(): void
    {
        $field = Select::make('customer_id')->forModel(TsOrder::class)->relationship('customer', 'name');
        $b     = TsCustomer::where('name', 'Ben B')->sole();

        $this->assertSame(['Ana A'], array_values($field->toData('create')->options));
        $this->assertFalse(Validator::make(['x' => $b->id], ['x' => [$field->strictOptionRule()]])->passes());

        $open = Select::make('customer_id')->forModel(TsOrder::class)->relationship('customer', 'name')->withoutTeamScope();
        $this->assertCount(3, $open->toData('create')->options);
    }

    public function test_filter_relationship_options_are_team_bound(): void
    {
        foreach ([SelectFilter::class, MultiSelectFilter::class] as $class) {
            $bound = $class::make('customer_id')->forModel(TsOrder::class)->relationship('customer', 'name')->toData();
            $open  = $class::make('customer_id')->forModel(TsOrder::class)->relationship('customer', 'name')->withoutTeamScope()->toData();
            $glob  = $class::make('customer_id')->forModel(TsOrder::class)->relationship('customer', 'name')->teamScopeIncludesGlobal()->toData();

            $this->assertSame(['Ana A'], array_values($bound->options));
            $this->assertCount(3, $open->options);
            $this->assertEqualsCanonicalizing(['Ana A', 'Nadia Nobody'], array_values($glob->options));
        }
    }

    public function test_multi_select_filter_and_checkbox_list_search_end_to_end(): void
    {
        $multi = MultiSelectFilter::make('c')->searchUsing(TsCustomer::class)->toData()->searchToken;
        $list  = CheckboxList::make('c')->searchUsing(TsCustomer::class)->toData('create')->searchToken;

        $this->assertSame(['Ana A'], $this->search((string) $multi));
        $this->assertSame(['Ana A'], $this->search((string) $list));
    }

    public function test_a_migration_run_forgets_the_remembered_schema(): void
    {
        $this->search($this->token());

        DB::enableQueryLog();
        event(new MigrationsEnded('up'));
        $this->search($this->token());

        $this->assertGreaterThan(0, $this->schemaQueries());
    }

    public function test_a_closure_modifier_with_or_where_cannot_escape_the_team_bound(): void
    {
        $modifier = fn ($q) => $q->where('name', 'like', 'A%')->orWhere('name', 'Ben B');
        $foreign  = TsCustomer::where('name', 'Ben B')->sole();

        $select = Select::make('customer_id')->forModel(TsOrder::class)->relationship('customer', 'name', $modifier);
        $list   = CheckboxList::make('customer_id')->forModel(TsOrder::class)->relationship('customer', 'name', $modifier);

        foreach ([$select, $list] as $field) {
            $this->assertSame(['Ana A'], array_values($field->toData('create')->options));
            $this->assertFalse(Validator::make(['x' => $foreign->id], ['x' => [$field->strictOptionRule()]])->passes());
        }

        $filter = SelectFilter::make('customer_id')->forModel(TsOrder::class)
            ->relationship('customer', 'name', fn ($q) => $q->orWhere('name', 'Ben B'))->toData();

        $this->assertSame([], array_values($filter->options));
    }

    public function test_a_where_not_modifier_keeps_its_negation(): void
    {
        TsCustomer::create(['team_id' => $this->teamA->id, 'name' => 'Alma A']);
        $ana = TsCustomer::where('name', 'Ana A')->sole();

        $select = Select::make('customer_id')->forModel(TsOrder::class)
            ->relationship('customer', 'name', fn ($q) => $q->whereNot('name', 'Ana A'));

        $this->assertSame(['Alma A'], array_values($select->toData('create')->options));
        $this->assertFalse(Validator::make(['x' => $ana->id], ['x' => [$select->strictOptionRule()]])->passes());

        $filter = SelectFilter::make('customer_id')->forModel(TsOrder::class)
            ->relationship('customer', 'name', fn ($q) => $q->whereNot('name', 'Ana A'))->toData();

        $this->assertSame(['Alma A'], array_values($filter->options));

        $leadingOrNot = Select::make('customer_id')->forModel(TsOrder::class)
            ->relationship('customer', 'name', fn ($q) => $q->orWhereNot('name', 'Ana A'));

        $this->assertSame(['Alma A'], array_values($leadingOrNot->toData('create')->options));
    }

    public function test_a_modifier_limit_never_exceeds_the_options_cap(): void
    {
        config(['kinetix.forms.relationship_options_limit' => 1]);
        TsCustomer::create(['team_id' => $this->teamA->id, 'name' => 'Alma A']);

        $select = Select::make('customer_id')->forModel(TsOrder::class)
            ->relationship('customer', 'name', fn ($q) => $q->limit(100));

        $this->assertCount(1, $select->toData('create')->options);
    }

    public function test_a_closure_modifier_keeps_its_ordering(): void
    {
        $field = Select::make('customer_id')->forModel(TsOrder::class)->relationship('customer', 'name', fn ($q) => $q->orderByDesc('name'));

        $this->assertSame(['Ana A'], array_values($field->toData('create')->options));
    }

    public function test_a_strict_scoped_to_team_model_rejects_null_rows_unless_it_declares_them_shared(): void
    {
        $global = TsProject::create(['team_id' => null, 'name' => 'Global']);
        $own    = TsProject::create(['team_id' => $this->teamA->id, 'name' => 'Alpha']);

        $field = Select::make('customer_id')->forModel(TsOrder::class)->relationship('project', 'name');
        $rule  = $field->strictOptionRule();

        $this->assertTrue(Validator::make(['x' => $own->id], ['x' => [$rule]])->passes());
        $this->assertFalse(Validator::make(['x' => $global->id], ['x' => [$rule]])->passes());

        $shared = $field->teamScopeIncludesGlobal()->strictOptionRule();
        $this->assertTrue(Validator::make(['x' => $global->id], ['x' => [$shared]])->passes());
    }

    public function test_an_explicit_constraint_on_a_custom_role_team_key_wins(): void
    {
        Schema::table('ts_roles', fn (Blueprint $t) => $t->unsignedInteger('company_id')->nullable());
        config()->set('permission.models.role', TsRole::class);
        config()->set('permission.teams', true);
        config()->set('permission.column_names.team_foreign_key', 'company_id');

        TsRole::create(['company_id' => null, 'name' => 'admin']);
        TsRole::create(['company_id' => 1, 'name' => 'Ops']);
        TsRole::create(['company_id' => 2, 'name' => 'BRole']);

        $this->assertSame(['BRole'], SearchScope::apply(TsRole::query(), ['company_id' => 2])->pluck('name')->all());
    }

    public function test_the_state_reset_clears_the_in_process_memo_but_the_store_still_answers(): void
    {
        $this->search($this->token());

        DB::enableQueryLog();
        KinetixStateReset::run(false);
        $this->search($this->token());

        $this->assertSame(0, $this->schemaQueries());
    }

    public function test_without_a_schema_cache_ttl_the_reset_asks_the_schema_again(): void
    {
        config()->set('kinetix.search.schema_cache_ttl', null);
        $this->search($this->token());

        DB::enableQueryLog();
        KinetixStateReset::run(false);
        $this->search($this->token());

        $this->assertGreaterThan(0, $this->schemaQueries());
    }

    public function test_the_schema_is_not_queried_again_once_known(): void
    {
        DB::enableQueryLog();
        $this->search($this->token());
        $first = $this->schemaQueries();

        DB::flushQueryLog();
        $this->search($this->token());
        $second = $this->schemaQueries();

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $second);
    }

    private function schemaQueries(): int
    {
        return collect(DB::getQueryLog())
            ->filter(fn (array $q) => (bool) preg_match('/pragma|sqlite_master|information_schema|table_info/i', $q['query']))
            ->count();
    }
}
