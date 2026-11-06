<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Concerns;

use Happones\Kinetix\Permissions\SuperAdmin;
use Happones\Kinetix\Permissions\TeamOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;

/**
 * The audit probe's app, for query-count regression tests: teams on with slug
 * `{current_team}` routes, a member of 5 teams, a role with 50 permissions in
 * the current team, 20 database notifications, and — in the all-modules mode —
 * every optional module enabled (tours on the database driver, billing with a
 * plan, announcements, onboarding, the team switcher, accessibility,
 * dismissals, presence, the owner bypass).
 */
trait BuildsAllModulesFixture
{
    use CreatesPermissionTables;

    protected PerfUser $member;

    protected PerfTeam $team;

    /**
     * @param Application $app
     */
    protected function useTeamsWithNotifications($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('kinetix.teams', true);
        $app['config']->set('kinetix.notifications.database', true);
        $app['config']->set('auth.providers.users.model', PerfUser::class);
    }

    /**
     * @param Application $app
     */
    protected function useAllModules($app): void
    {
        foreach (array_keys((array) $app['config']->get('kinetix')) as $key) {
            if (is_array($app['config']->get("kinetix.{$key}")) && $app['config']->has("kinetix.{$key}.enabled")) {
                $app['config']->set("kinetix.{$key}.enabled", true);
            }
        }

        $this->useTeamsWithNotifications($app);

        $app['config']->set('kinetix.permissions.teams', true);
        $app['config']->set('permission.teams', true);
        $app['config']->set('kinetix.permissions.owner_bypass', true);
        $app['config']->set('kinetix.tours.driver', 'database');
        $app['config']->set('kinetix.features.driver', 'native');
        $app['config']->set('kinetix.billing.billable', PerfTeam::class);
        $app['config']->set('kinetix.membership.attach_member', null);
    }

    protected function definePerfRoutes(Router $router): void
    {
        $router->middleware('web')->get('dashboard', static fn () => Inertia::render('Dashboard'));
        $router->middleware(['web', 'kinetix.permissions.team'])->get('{current_team}/dashboard', static fn () => Inertia::render('Dashboard'));
    }

    protected function buildFixture(bool $withPermissions): void
    {
        if ($withPermissions) {
            $this->createPermissionTables();
            (require __DIR__.'/../../database/migrations/2026_01_01_000017_add_kinetix_team_fields_to_permission_tables.php')->up();
        } else {
            Schema::create('users', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name')->nullable();
            });
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->unsignedInteger('current_team_id')->nullable();
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('slug')->unique();
            $table->string('name');
            $table->unsignedInteger('owner_id')->nullable();
        });

        Schema::create('team_user', function (Blueprint $table): void {
            $table->unsignedInteger('team_id');
            $table->unsignedInteger('user_id');
        });

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        foreach (glob(__DIR__.'/../../database/migrations/*.php') ?: [] as $file) {
            if (str_contains($file, '000017_')) {
                continue;
            }

            (require $file)->up();
        }

        $owner = PerfUser::create(['name' => 'Owner', 'email' => 'owner@example.com']);

        $teams = [];

        for ($i = 1; $i <= 5; $i++) {
            $teams[] = PerfTeam::create(['slug' => "team-{$i}", 'name' => "Team {$i}", 'owner_id' => $owner->getKey()]);
        }

        $this->team   = $teams[0];
        $this->member = PerfUser::create(['name' => 'Member', 'email' => 'member@example.com', 'current_team_id' => $this->team->getKey()]);

        foreach ($teams as $team) {
            DB::table('team_user')->insert(['team_id' => $team->getKey(), 'user_id' => $this->member->getKey()]);
        }

        for ($i = 0; $i < 20; $i++) {
            DB::table('notifications')->insert([
                'id'              => (string) Str::uuid(),
                'type'            => 'App\\Notifications\\Ping',
                'notifiable_type' => $this->member->getMorphClass(),
                'notifiable_id'   => $this->member->getKey(),
                'data'            => json_encode(['title' => "Ping {$i}", 'status' => 'info', 'team' => $this->team->getKey()]),
                'created_at'      => now()->subMinutes($i),
                'updated_at'      => now()->subMinutes($i),
            ]);
        }

        if ($withPermissions) {
            $registrar = app(PermissionRegistrar::class);
            $registrar->setPermissionsTeamId($this->team->getKey());

            $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);

            for ($i = 1; $i <= 50; $i++) {
                $role->givePermissionTo(Permission::create(['name' => "posts.ability{$i}", 'guard_name' => 'web']));
            }

            $this->member->assignRole($role);
            $registrar->setPermissionsTeamId(null);
            $registrar->forgetCachedPermissions();
            $this->member->unsetRelation('roles')->unsetRelation('permissions');
            SuperAdmin::flush();
            TeamOwner::flush();
        }

        if (Schema::hasTable('plans')) {
            DB::table('plans')->insert([
                'slug'       => 'pro',
                'name'       => 'Pro',
                'features'   => json_encode(['projects' => 10]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * A full Inertia visit of the member's dashboard; the response JSON.
     *
     * @param  array<string, string> $headers
     * @return array<string, mixed>
     */
    protected function visitDashboard(bool $teamRouted = true, array $headers = []): array
    {
        $this->actingAs($this->member);

        return $this->withHeaders(['X-Inertia' => 'true', ...$headers])
            ->get($teamRouted ? $this->team->slug.'/dashboard' : 'dashboard')
            ->assertOk()
            ->json();
    }
}

class PerfTeam extends Model
{
    protected $table = 'teams';

    public $timestamps = false;

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}

class PerfUser extends Authenticatable
{
    use HasRoles;
    use Notifiable;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected $guard_name = 'web';

    /**
     * @return BelongsToMany<PerfTeam, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(PerfTeam::class, 'team_user', 'user_id', 'team_id');
    }

    /**
     * @return BelongsTo<PerfTeam, $this>
     */
    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(PerfTeam::class, 'current_team_id');
    }

    public function ownsTeam(mixed $team): bool
    {
        return $team instanceof PerfTeam && (int) $team->owner_id === (int) $this->getKey();
    }
}
