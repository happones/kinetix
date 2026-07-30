<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Permissions\KinetixPermissions;
use Happones\Kinetix\Permissions\TeamOwner;
use Happones\Kinetix\Tests\Concerns\CreatesPermissionTables;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Permission\Traits\HasRoles;

class OwnerTeam extends Model
{
    protected $table = 'teams';

    public $timestamps = false;

    protected $guarded = [];
}

class OwnerUser extends Authenticatable
{
    use HasRoles;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected $guard_name = 'web';

    /**
     * @return BelongsToMany<OwnerTeam, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(OwnerTeam::class, 'team_user', 'user_id', 'team_id');
    }

    public function ownsTeam(mixed $team): bool
    {
        return $team instanceof OwnerTeam && (int) $team->owner_id === (int) $this->getKey();
    }
}

/**
 * The bypass as an invokable class — the `config:cache`-safe way to express a
 * callback in a config file.
 */
class OwnsByAttribute
{
    public function __invoke(mixed $user, mixed $team): bool
    {
        return $team instanceof OwnerTeam && (int) $team->owner_id === (int) $user->getKey();
    }
}

class PermissionOwnerBypassTest extends TestCase
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
        $app['config']->set('kinetix.permissions.enabled', true);
        $app['config']->set('kinetix.permissions.teams', true);
        $app['config']->set('permission.teams', true);

        // The bypass has to be configured BEFORE the provider boots, since that
        // is where the Gate::before callback is registered.
        $app['config']->set('kinetix.permissions.owner_bypass', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPermissionTables();
        $migration = require __DIR__.'/../../database/migrations/2026_01_01_000017_add_kinetix_team_fields_to_permission_tables.php';
        $migration->up();

        Schema::create('teams', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('owner_id');
        });

        Schema::create('team_user', function (Blueprint $table) {
            $table->unsignedInteger('team_id');
            $table->unsignedInteger('user_id');
        });

        KinetixPermissions::feature('posts')->crud();

        TeamOwner::flush();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        TeamOwner::flush();

        parent::tearDown();
    }

    public function test_the_owner_of_the_scoped_team_passes_every_gate_without_a_role(): void
    {
        [$owner, $member, $team] = $this->makeTeam();

        app(PermissionRegistrar::class)->setPermissionsTeamId($team->getKey());

        $this->assertTrue(Gate::forUser($owner)->allows('posts.delete'));
        $this->assertFalse(Gate::forUser($member)->allows('posts.delete'));
    }

    public function test_ownership_is_re_evaluated_per_team(): void
    {
        [$owner, , $team] = $this->makeTeam();

        $other = OwnerTeam::create(['owner_id' => 999]);
        $owner->teams()->attach($other->getKey());

        app(PermissionRegistrar::class)->setPermissionsTeamId($team->getKey());
        $this->assertTrue(Gate::forUser($owner)->allows('posts.update'));

        // Same user, a team they belong to but do not own → no bypass.
        app(PermissionRegistrar::class)->setPermissionsTeamId($other->getKey());
        $this->assertFalse(Gate::forUser($owner)->allows('posts.update'));
    }

    public function test_a_team_the_user_does_not_belong_to_never_grants_the_bypass(): void
    {
        [$owner] = $this->makeTeam();

        $foreign = OwnerTeam::create(['owner_id' => $owner->getKey()]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($foreign->getKey());

        $this->assertFalse(Gate::forUser($owner)->allows('posts.view'));
    }

    public function test_an_invokable_class_string_may_replace_the_default_rule(): void
    {
        config()->set('kinetix.permissions.owner_bypass', OwnsByAttribute::class);
        TeamOwner::flush();

        [$owner, $member, $team] = $this->makeTeam();

        app(PermissionRegistrar::class)->setPermissionsTeamId($team->getKey());

        $this->assertTrue(TeamOwner::enabled());
        $this->assertTrue(Gate::forUser($owner)->allows('posts.create'));
        $this->assertFalse(Gate::forUser($member)->allows('posts.create'));
    }

    public function test_it_stays_off_by_default(): void
    {
        config()->set('kinetix.permissions.owner_bypass', null);
        TeamOwner::flush();

        [$owner, , $team] = $this->makeTeam();

        app(PermissionRegistrar::class)->setPermissionsTeamId($team->getKey());

        $this->assertFalse(TeamOwner::enabled());
        $this->assertFalse(TeamOwner::check($owner));
    }

    /**
     * @return array{0: OwnerUser, 1: OwnerUser, 2: OwnerTeam}
     */
    private function makeTeam(): array
    {
        $owner  = OwnerUser::create(['name' => 'Owner']);
        $member = OwnerUser::create(['name' => 'Member']);
        $team   = OwnerTeam::create(['owner_id' => $owner->getKey()]);

        $owner->teams()->attach($team->getKey());
        $member->teams()->attach($team->getKey());

        return [$owner, $member, $team];
    }
}
