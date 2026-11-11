<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Entitlements\KinetixEntitlements;
use Happones\Kinetix\Entitlements\Middleware\EnsureEntitled;
use Happones\Kinetix\KinetixServiceProvider;
use Happones\Kinetix\Permissions\Middleware\SetPermissionsTeam;
use Happones\Kinetix\Support\KinetixTeams;
use Happones\Kinetix\Tests\Concerns\CreatesPermissionTables;
use Happones\Kinetix\Tests\TestCase;
use Happones\Kinetix\Tokens\EnsureTokenTeam;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\SortedMiddleware;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Permission\Traits\HasRoles;

class PriorityTeam extends Model
{
    protected $table = 'teams';

    public $timestamps = false;

    protected $guarded = [];
}

class PriorityUser extends Authenticatable
{
    use HasRoles;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected $guard_name = 'web';

    /**
     * @return BelongsToMany<PriorityTeam, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(PriorityTeam::class, 'team_user', 'user_id', 'team_id');
    }
}

/**
 * The kernel priority list: the token team before route binding, and the
 * checks that read the team after the team-id bridge they depend on.
 */
class MiddlewarePriorityTest extends TestCase
{
    use CreatesPermissionTables;

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

        $app['config']->set('cache.default', 'array');
        $app['config']->set('kinetix.teams', true);
        $app['config']->set('kinetix.permissions.enabled', true);
        $app['config']->set('kinetix.permissions.teams', true);
        $app['config']->set('permission.teams', true);
        $app['config']->set('auth.providers.users.model', PriorityUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPermissionTables();
        (require __DIR__.'/../../database/migrations/2026_01_01_000017_add_kinetix_team_fields_to_permission_tables.php')->up();

        Schema::create('teams', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });
        Schema::create('team_user', function ($table) {
            $table->unsignedInteger('team_id');
            $table->unsignedInteger('user_id');
        });

        PriorityTeam::create(['id' => 7, 'name' => 'Seven']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return array<int, string>
     */
    private function priority(): array
    {
        return app(Kernel::class)->getMiddlewarePriority();
    }

    public function test_the_token_team_runs_before_binding_and_the_team_readers_after_it(): void
    {
        $priority = $this->priority();
        $at       = static fn (string $class): int|false => array_search($class, $priority, true);

        $this->assertLessThan($at(SubstituteBindings::class), $at(EnsureTokenTeam::class));
        $this->assertGreaterThan($at(SubstituteBindings::class), $at(SetPermissionsTeam::class));
        $this->assertLessThan($at(EnsureEntitled::class), $at(SetPermissionsTeam::class));
        $this->assertLessThan($at(RoleMiddleware::class), $at(EnsureEntitled::class));
        $this->assertLessThan($at(Authorize::class), $at(RoleMiddleware::class));
    }

    public function test_a_kernel_without_binding_keeps_the_readers_after_throttling_and_before_authorize(): void
    {
        $auth     = AuthenticatesRequests::class;
        $throttle = ThrottleRequests::class;

        $priority = KinetixServiceProvider::prioritizeTeamMiddleware([StartSession::class, $auth, $throttle, Authorize::class]);
        $priority = KinetixServiceProvider::prioritizeTeamMiddleware($priority);
        $at       = static fn (string $class): int|false => array_search($class, $priority, true);

        $this->assertSame($at($auth) + 1, $at(EnsureTokenTeam::class));
        $this->assertGreaterThan($at($throttle), $at(SetPermissionsTeam::class));
        $this->assertLessThan($at(Authorize::class), $at(RoleMiddleware::class));
        $this->assertCount(1, array_keys($priority, EnsureTokenTeam::class, true));
        $this->assertCount(1, array_keys($priority, SetPermissionsTeam::class, true));
    }

    public function test_the_sorted_stack_keeps_the_team_bridge_before_the_role_check(): void
    {
        $sorted = (new SortedMiddleware($this->priority(), [
            EncryptCookies::class,
            StartSession::class,
            SubstituteBindings::class,
            SetPermissionsTeam::class,
            Authenticate::class,
            RoleMiddleware::class.':admin',
        ]))->all();

        $position = static fn (string $prefix): int => (int) array_key_first(array_filter(
            $sorted,
            static fn (string $middleware): bool => str_starts_with($middleware, $prefix),
        ));

        $this->assertLessThan($position(RoleMiddleware::class), $position(SetPermissionsTeam::class));
        $this->assertLessThan($position(SetPermissionsTeam::class), $position(Authenticate::class));
    }

    public function test_a_session_route_with_the_documented_stack_passes_for_a_team_assigned_role(): void
    {
        KinetixEntitlements::define('open')->permission('open.ability');
        Gate::define('open.ability', fn (): bool => true);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(7);
        Role::create(['name' => 'admin', 'guard_name' => 'web', 'team_id' => 7]);

        $user = PriorityUser::create(['name' => 'Ada']);
        $user->teams()->attach(7);
        $user->assignRole('admin');
        $registrar->setPermissionsTeamId(null);

        app('router')->aliasMiddleware('role', RoleMiddleware::class);

        Route::middleware(['kinetix.permissions.team', 'auth', 'role:admin', 'kinetix.entitled:open'])
            ->get('/{current_team}/ses', fn () => response()->json(['team' => KinetixTeams::currentTeamKey()]));

        $this->actingAs($user)->getJson('/7/ses')->assertOk()->assertJsonPath('team', 7);
    }
}
