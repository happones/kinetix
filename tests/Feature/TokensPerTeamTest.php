<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Api\ApiLog;
use Happones\Kinetix\Billing\BillingManager;
use Happones\Kinetix\Data\TokenData;
use Happones\Kinetix\Entitlements\KinetixEntitlements;
use Happones\Kinetix\Entitlements\Middleware\EnsureEntitled;
use Happones\Kinetix\Permissions\PermissionTeam;
use Happones\Kinetix\Support\KinetixTeams;
use Happones\Kinetix\Tests\TestCase;
use Happones\Kinetix\Tokens\EnsureTokenTeam;
use Happones\Kinetix\Tokens\KinetixTokens;
use Happones\Kinetix\Tokens\TokenScopeRegistry;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\SanctumServiceProvider;
use Spatie\Permission\PermissionServiceProvider;

class PerTeamTokenTeam extends Model
{
    protected $table = 'teams';

    public $timestamps = false;

    protected $guarded = [];

    /** Route teams by slug instead of id. */
    public static bool $bySlug = false;

    public function getRouteKeyName(): string
    {
        return self::$bySlug ? 'slug' : 'id';
    }
}

class PerTeamTokenUser extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return BelongsToMany<PerTeamTokenTeam, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(PerTeamTokenTeam::class, 'team_user', 'user_id', 'team_id');
    }

    /**
     * @return BelongsTo<PerTeamTokenTeam, $this>
     */
    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(PerTeamTokenTeam::class, 'current_team_id');
    }
}

/**
 * Tokens that belong to ONE team: sealed on create (column or ability), listed
 * and revoked only from their team, resolved to that team on API requests
 * (never to the holder's last-opened team), and logged under it.
 */
class TokensPerTeamTest extends TestCase
{
    /**
     * @param  Application              $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), SanctumServiceProvider::class, PermissionServiceProvider::class];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.teams', true);
        $app['config']->set('kinetix.tokens.enabled', true);
        $app['config']->set('kinetix.billing.teams', true);
        $app['config']->set('kinetix.tokens.team_ability', 'team:');
        $app['config']->set('kinetix.tokens.stamp_abilities', ['integration']);
        $app['config']->set('kinetix.api_logs.enabled', true);
        $app['config']->set('auth.providers.users.model', PerTeamTokenUser::class);
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->unsignedInteger('current_team_id')->nullable();
        });
        Schema::create('teams', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
        });
        Schema::create('team_user', function (Blueprint $table) {
            $table->unsignedInteger('team_id');
            $table->unsignedInteger('user_id');
        });
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->timestamps();
        });

        (require __DIR__.'/../../database/migrations/2026_01_01_000018_create_kinetix_api_logs_table.php')->up();
        (require __DIR__.'/../../database/migrations/2026_01_01_000026_add_team_id_to_kinetix_api_logs_table.php')->up();

        PerTeamTokenTeam::$bySlug = false;
        PerTeamTokenTeam::create(['id' => 7, 'name' => 'Seven', 'slug' => 'seven']);
        PerTeamTokenTeam::create(['id' => 8, 'name' => 'Eight', 'slug' => 'eight']);

        // The scope catalog is empty: tokens are full access unless asked.
        $this->app->instance(TokenScopeRegistry::class, new TokenScopeRegistry);

        Route::middleware(['auth:sanctum', 'kinetix.token-team'])
            ->get('/api/{current_team}/probe', fn () => response()->json(['team' => KinetixTeams::keyFor('tokens')]));

        Route::bind('probeThing', fn ($value) => ['bound_with' => KinetixTeams::currentTeamKey()]);

        // The binding middleware is listed FIRST on purpose: the kernel's
        // priority list must still run the token team before it.
        Route::middleware([SubstituteBindings::class, 'auth:sanctum', 'kinetix.token-team'])
            ->get('/api/things/{probeThing}', fn ($probeThing) => response()->json(['bound' => $probeThing]));

        Route::middleware(['auth:sanctum', 'kinetix.token-team'])
            ->get('/api/billable', fn () => response()->json([
                'billable' => BillingManager::resolve()->billable()->getKey(),
                'route'    => KinetixTeams::currentRouteKey(),
            ]));

        Route::middleware(['auth:sanctum', 'kinetix.token-team'])
            ->get('/api/stream', fn () => response()->stream(function (): void {
                echo json_encode(['team' => KinetixTeams::currentTeamKey()]);
            }));

        Route::middleware(['auth:sanctum', 'kinetix.token-team', 'kinetix.api-log'])
            ->get('/api/whoami', fn () => response()->json([
                'team'   => KinetixTeams::keyFor('tokens'),
                'pinned' => PermissionTeam::pinned()[1] ?? null,
            ]));
    }

    /**
     * @param array<int, int> $teams
     */
    private function member(array $teams = [7, 8], int $current = 8): PerTeamTokenUser
    {
        $user = PerTeamTokenUser::create(['name' => 'Dev', 'current_team_id' => $current]);
        $user->teams()->attach($teams);

        return $user;
    }

    /**
     * @param  array<int, string>         $abilities
     * @return array{0: Model, 1: string}
     */
    private function token(PerTeamTokenUser $user, array $abilities): array
    {
        $new = $user->createToken('t', $abilities);

        return [$new->accessToken, $new->plainTextToken];
    }

    public function test_create_seals_the_token_to_the_team_in_the_url_and_stamps_abilities(): void
    {
        $user = $this->member();

        $response = $this->actingAs($user)->postJson('/7/_kinetix/tokens', ['name' => 'CI'])->assertCreated();

        $stored = $user->tokens()->firstOrFail();
        $this->assertEqualsCanonicalizing(['*', 'team:7', 'integration'], $stored->abilities);
        $this->assertSame(7, KinetixTokens::teamOf($stored));

        // Plumbing stays out of the UI payload.
        $this->assertSame(['*'], $response->json('token.abilities'));
    }

    public function test_the_form_cannot_set_a_team_seal(): void
    {
        $user = $this->member();

        $this->actingAs($user)->postJson('/7/_kinetix/tokens', ['name' => 'Sneaky', 'abilities' => ['team:8', 'posts.read']])
            ->assertStatus(422);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_the_list_and_revocation_are_filtered_per_team(): void
    {
        $user = $this->member();

        [$legacy]  = $this->token($user, ['posts.read']);
        [$ofSeven] = $this->token($user, ['posts.read', 'team:7', 'integration']);
        [$ofEight] = $this->token($user, ['posts.read', 'team:8', 'integration']);

        $names = static fn (array $tokens): array => array_column($tokens, 'id');

        $seven = $this->actingAs($user)->getJson('/7/_kinetix/tokens')->json('tokens');
        $this->assertEqualsCanonicalizing([$legacy->getKey(), $ofSeven->getKey()], $names($seven));

        $eight = $this->actingAs($user)->getJson('/8/_kinetix/tokens')->json('tokens');
        $this->assertEqualsCanonicalizing([$legacy->getKey(), $ofEight->getKey()], $names($eight));

        // Team A cannot revoke team B's token.
        $this->actingAs($user)->deleteJson('/7/_kinetix/tokens/'.$ofEight->getKey())->assertOk();
        $this->assertNotNull($ofEight->fresh());

        $this->actingAs($user)->deleteJson('/7/_kinetix/tokens/'.$ofSeven->getKey())->assertOk();
        $this->assertNull($ofSeven->fresh());
    }

    public function test_the_team_column_seals_and_filters_too(): void
    {
        config()->set('kinetix.tokens.team_ability', null);
        config()->set('kinetix.tokens.stamp_abilities', []);
        config()->set('kinetix.tokens.team_column', 'team_id');

        $user = $this->member();

        $this->actingAs($user)->postJson('/7/_kinetix/tokens', ['name' => 'col'])->assertCreated();
        $mine = $user->tokens()->firstOrFail();
        $this->assertSame(7, (int) $mine->team_id);
        $this->assertSame(7, (int) KinetixTokens::teamOf($mine));

        [$theirs] = $this->token($user, ['*']);
        $theirs->forceFill(['team_id' => 8])->save();

        $ids = array_column($this->actingAs($user)->getJson('/7/_kinetix/tokens')->json('tokens'), 'id');
        $this->assertSame([$mine->getKey()], $ids);

        $this->actingAs($user)->deleteJson('/7/_kinetix/tokens/'.$theirs->getKey())->assertOk();
        $this->assertNotNull($theirs->fresh());
    }

    public function test_team_of_reads_the_column_first_then_the_seal(): void
    {
        config()->set('kinetix.tokens.team_column', 'team_id');
        $user = $this->member();

        [$token] = $this->token($user, ['team:7']);
        $this->assertSame(7, KinetixTokens::teamOf($token));

        $token->forceFill(['team_id' => 8])->save();
        $this->assertSame(8, (int) KinetixTokens::teamOf($token));

        [$bare] = $this->token($user, ['posts.read']);
        $this->assertNull(KinetixTokens::teamOf($bare));
    }

    public function test_token_data_hides_the_seal_and_the_stamps(): void
    {
        [$token] = $this->token($this->member(), ['posts.read', 'team:7', 'integration']);

        $this->assertSame(['posts.read'], TokenData::fromModel($token)->abilities);
    }

    public function test_the_api_request_acts_on_the_tokens_team_not_the_holders_current_team(): void
    {
        $user = $this->member(current: 8);

        [, $plain] = $this->token($user, ['*', 'team:7']);

        $this->withToken($plain)->getJson('/api/whoami')
            ->assertOk()
            ->assertJsonPath('team', 7)
            ->assertJsonPath('pinned', 7);

        // The pin is gone once the request is.
        $this->assertNull(PermissionTeam::pinned());
    }

    public function test_a_teamless_token_falls_through_to_the_current_team(): void
    {
        [, $plain] = $this->token($this->member(current: 8), ['*']);

        $this->withToken($plain)->getJson('/api/whoami')->assertOk()->assertJsonPath('team', 8);
    }

    public function test_require_team_rejects_a_teamless_token(): void
    {
        config()->set('kinetix.tokens.require_team', true);

        [, $plain]  = $this->token($this->member(), ['*']);
        [, $sealed] = $this->token($this->member(), ['*', 'team:7']);

        $this->withToken($plain)->getJson('/api/whoami')->assertForbidden();

        // The guard keeps the first request's user for the whole test.
        $this->app['auth']->forgetGuards();

        $this->withToken($sealed)->getJson('/api/whoami')->assertOk();
    }

    public function test_a_user_who_left_the_team_is_refused(): void
    {
        $user = $this->member([8], current: 8);

        [, $plain] = $this->token($user, ['*', 'team:7']);

        $this->withToken($plain)->getJson('/api/whoami')->assertForbidden();
    }

    public function test_teams_off_leaves_the_request_alone(): void
    {
        config()->set('kinetix.teams', false);

        [, $plain] = $this->token($this->member([], current: 8), ['*', 'team:7']);

        $this->withToken($plain)->getJson('/api/whoami')->assertOk()->assertJsonPath('pinned', null);
    }

    public function test_the_api_log_is_attributed_to_the_tokens_team(): void
    {
        $user = $this->member(current: 8);

        [, $plain] = $this->token($user, ['*', 'team:7']);

        $this->withToken($plain)->getJson('/api/whoami')->assertOk();

        $this->assertSame(7, (int) ApiLog::query()->firstOrFail()->team_id);
    }

    public function test_the_api_log_of_a_teamless_token_keeps_the_holders_team(): void
    {
        [, $plain] = $this->token($this->member(current: 8), ['*']);

        $this->withToken($plain)->getJson('/api/whoami')->assertOk();

        $this->assertSame(8, (int) ApiLog::query()->firstOrFail()->team_id);
    }

    public function test_sealing_without_a_team_is_refused(): void
    {
        $user = PerTeamTokenUser::create(['name' => 'Loner']);

        // Not even the user's own team resolves: nothing to seal to.
        $this->actingAs($user)->postJson('/7/_kinetix/tokens', ['name' => 'x'])->assertStatus(404);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_the_doctor_flags_a_configured_team_column_the_table_lacks(): void
    {
        config()->set('kinetix.tokens.team_column', 'company_id');

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain("tokens.team_column is 'company_id'")
            ->assertFailed();
    }

    public function test_the_doctor_accepts_an_existing_team_column(): void
    {
        config()->set('kinetix.tokens.team_column', 'team_id');

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('tokens are scoped to a team through personal_access_tokens.team_id');
    }

    private function sealedTo7(): string
    {
        return $this->token($this->member(current: 8), ['*', 'team:7'])[1];
    }

    public function test_a_token_cannot_reach_another_teams_route_segment(): void
    {
        $plain = $this->sealedTo7();

        $this->withToken($plain)->getJson('/api/7/probe')->assertOk()->assertJsonPath('team', 7);

        $this->app['auth']->forgetGuards();

        $this->withToken($plain)->getJson('/api/8/probe')->assertForbidden();
    }

    public function test_the_segment_is_compared_by_the_teams_route_key(): void
    {
        PerTeamTokenTeam::$bySlug = true;
        $plain                    = $this->sealedTo7();

        $this->withToken($plain)->getJson('/api/seven/probe')->assertOk()->assertJsonPath('team', 7);

        $this->app['auth']->forgetGuards();

        $this->withToken($plain)->getJson('/api/eight/probe')->assertForbidden();
    }

    public function test_a_segment_of_a_team_the_holder_is_not_in_is_still_a_404(): void
    {
        PerTeamTokenTeam::create(['id' => 9, 'name' => 'Nine', 'slug' => 'nine']);

        $this->withToken($this->sealedTo7())->getJson('/api/9/probe')->assertNotFound();
    }

    public function test_route_model_bindings_resolve_under_the_tokens_team(): void
    {
        $this->withToken($this->sealedTo7())->getJson('/api/things/1')
            ->assertOk()
            ->assertJsonPath('bound.bound_with', 7);
    }

    public function test_billing_resolves_the_tokens_team_and_the_route_key_follows_the_pin(): void
    {
        $this->withToken($this->sealedTo7())->getJson('/api/billable')
            ->assertOk()
            ->assertJsonPath('billable', 7)
            ->assertJsonPath('route', 7);
    }

    public function test_a_streamed_body_still_runs_under_the_pin(): void
    {
        $response = $this->withToken($this->sealedTo7())->get('/api/stream');

        $this->assertSame(['team' => 7], json_decode($response->streamedContent(), true));
        $this->assertNull(PermissionTeam::pinned());
    }

    public function test_a_catalog_scope_with_the_seal_prefix_is_refused_and_not_read_as_a_seal(): void
    {
        app(TokenScopeRegistry::class)->register(['team:read' => 'Read team']);

        $user = $this->member(current: 7);

        $this->actingAs($user)->postJson('/7/_kinetix/tokens', ['name' => 'x', 'abilities' => ['team:read']])
            ->assertStatus(422);
        $this->assertSame(0, $user->tokens()->count());

        [$legacy] = $this->token($user, ['team:read']);
        $this->assertNull(KinetixTokens::teamOf($legacy));
        $this->assertSame(['team:read'], KinetixTokens::collidingScopes());

        $this->artisan('kinetix:doctor')->expectsOutputToContain('collide with tokens.team_ability')->assertFailed();
    }

    public function test_a_stamp_ability_cannot_be_requested(): void
    {
        $this->actingAs($this->member())->postJson('/7/_kinetix/tokens', ['name' => 'x', 'abilities' => ['integration']])
            ->assertStatus(422);
    }

    public function test_the_column_fails_closed_without_a_team(): void
    {
        config()->set('kinetix.tokens.team_ability', null);
        config()->set('kinetix.tokens.team_column', 'team_id');

        $user = PerTeamTokenUser::create(['name' => 'Loner']);

        $this->actingAs($user)->postJson('/7/_kinetix/tokens', ['name' => 'x'])->assertStatus(404);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_the_doctor_counts_unsealed_tokens(): void
    {
        $this->token($this->member(), ['posts.read']);

        $this->artisan('kinetix:doctor')->expectsOutputToContain('1 token(s) carry no team seal');
    }

    public function test_entitlements_are_judged_under_the_tokens_team_whatever_the_middleware_order(): void
    {
        Gate::define('probe.ability', fn ($user): bool => (int) KinetixTeams::currentTeamKey() === 7);
        KinetixEntitlements::define('probe')->permission('probe.ability');

        // Entitlement listed BEFORE the token team on purpose.
        Route::middleware(['auth:sanctum', 'kinetix.entitled:probe', 'kinetix.token-team'])
            ->get('/api/entitled', fn () => response()->json(['team' => KinetixTeams::currentTeamKey()]));

        $this->withToken($this->sealedTo7())->getJson('/api/entitled')->assertOk()->assertJsonPath('team', 7);

        // Same holder, a token of team 8: the memo of team 7's verdict is not reused.
        $this->app['auth']->forgetGuards();
        $other = $this->token(PerTeamTokenUser::query()->firstOrFail(), ['*', 'team:8'])[1];

        $this->withToken($other)->getJson('/api/entitled')->assertForbidden();
    }

    public function test_a_hosts_own_segment_binding_is_applied_to_the_comparison(): void
    {
        Route::bind('current_team', fn (string $value) => PerTeamTokenTeam::query()->where('slug', $value)->first());
        Route::middleware(['auth:sanctum', 'kinetix.token-team'])
            ->get('/api/bound/{current_team}/probe', fn () => response()->json(['team' => KinetixTeams::currentTeamKey()]));

        $plain = $this->sealedTo7();

        $this->withToken($plain)->getJson('/api/bound/seven/probe')->assertOk()->assertJsonPath('team', 7);

        $this->app['auth']->forgetGuards();

        $this->withToken($plain)->getJson('/api/bound/eight/probe')->assertForbidden();
    }

    public function test_the_priority_list_runs_the_token_team_before_binding_and_the_checks_after_it(): void
    {
        $priority = app(Kernel::class)->getMiddlewarePriority();

        $position = static fn (string $class): int|false => array_search($class, $priority, true);

        $this->assertLessThan($position(SubstituteBindings::class), $position(EnsureTokenTeam::class));
        $this->assertLessThan($position(EnsureEntitled::class), $position(EnsureTokenTeam::class));
    }

    public function test_the_seal_collision_message_is_translated(): void
    {
        $user = $this->member(current: 7);

        $message = $this->actingAs($user)->postJson('/7/_kinetix/tokens', ['name' => 'x', 'abilities' => ['team:8']])
            ->assertStatus(422)->json('errors.abilities\\.0.0');

        $this->assertNotSame('validation.not_in', $message);
    }
}
