<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Permissions\KinetixPermissions;
use Happones\Kinetix\Permissions\PermissionRegistry;
use Happones\Kinetix\Permissions\SuperAdmin;
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
use Inertia\Inertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Permission\Traits\HasRoles;

class ScopeTeam extends Model
{
    protected $table = 'teams';

    public $timestamps = false;

    protected $guarded = [];
}

class ScopeUser extends Authenticatable
{
    use HasRoles;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected $guard_name = 'web';

    /**
     * @return BelongsToMany<ScopeTeam, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(ScopeTeam::class, 'team_user', 'user_id', 'team_id');
    }

    public function ownsTeam(mixed $team): bool
    {
        return $team instanceof ScopeTeam && (int) $team->owner_id === (int) $this->getKey();
    }
}

/** A bespoke ownership rule as a config-cacheable callable array. */
class ScopeOwnership
{
    public static function check(mixed $user, mixed $team): bool
    {
        return $team instanceof ScopeTeam && (int) $team->owner_id === (int) $user->getKey();
    }
}

/**
 * The scoped team-owner bypass: `owner_bypass.using / only / except` plus the
 * platform marks, and the guarantee that the server Gate and the
 * `kinetix_permissions` prop apply one predicate.
 */
class PermissionOwnerBypassScopeTest extends TestCase
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
        $app['config']->set('auth.providers.users.model', ScopeUser::class);
        // Registers the platform-level `users.impersonate` at boot.
        $app['config']->set('kinetix.impersonation.enabled', true);

        // Before the provider boots: that is where the Gate::before lives.
        $app['config']->set('kinetix.permissions.owner_bypass', ['using' => true, 'only' => null, 'except' => []]);
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
        KinetixPermissions::feature('settings')->ability('manage');
        KinetixPermissions::feature('users')->ability('export');

        foreach (KinetixPermissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        TeamOwner::flush();
        SuperAdmin::flush();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        TeamOwner::flush();
        SuperAdmin::flush();

        parent::tearDown();
    }

    public function test_users_impersonate_is_registered_as_platform_level(): void
    {
        $this->assertTrue(app(PermissionRegistry::class)->isPlatform('users.impersonate'));
        $this->assertFalse(app(PermissionRegistry::class)->isPlatform('users.export'));
    }

    public function test_the_structured_true_form_behaves_like_the_legacy_true(): void
    {
        $owner = $this->scopedOwner();

        $structured = [$this->granted($owner), $this->shared($owner)];

        $this->configure(true);

        $this->assertSame($structured, [$this->granted($owner), $this->shared($owner)]);
        $this->assertSame($this->everyRegisteredBut(['users.impersonate']), $structured[0]);
    }

    public function test_except_removes_a_whole_feature(): void
    {
        $this->configure(['using' => true, 'except' => ['settings']]);

        $this->assertSame($this->everyRegisteredBut(['settings.manage', 'users.impersonate']), $this->granted($this->scopedOwner()));
    }

    public function test_except_removes_an_exact_key(): void
    {
        $this->configure(['using' => true, 'except' => ['posts.delete']]);

        $granted = $this->granted($this->scopedOwner());

        $this->assertNotContains('posts.delete', $granted);
        $this->assertContains('posts.update', $granted);
    }

    public function test_a_feature_star_pattern_is_the_whole_feature(): void
    {
        $this->configure(['using' => true, 'except' => ['users.*']]);

        $this->assertSame($this->everyRegisteredBut(['users.export', 'users.impersonate']), $this->granted($this->scopedOwner()));
    }

    public function test_other_wildcards_match_nothing(): void
    {
        $this->configure(['using' => true, 'except' => ['*', 'u*', 'posts.*.view', 'pos']]);

        $this->assertSame($this->everyRegisteredBut(['users.impersonate']), $this->granted($this->scopedOwner()));
    }

    public function test_only_limits_the_grant(): void
    {
        $this->configure(['using' => true, 'only' => ['posts', 'roles.manage']]);

        $this->assertEqualsCanonicalizing(
            ['posts.viewAny', 'posts.view', 'posts.create', 'posts.update', 'posts.delete', 'roles.manage'],
            $this->granted($this->scopedOwner()),
        );
    }

    public function test_except_wins_over_only(): void
    {
        $this->configure(['using' => true, 'only' => ['posts'], 'except' => ['posts.delete']]);

        $this->assertSame(
            ['posts.viewAny', 'posts.view', 'posts.create', 'posts.update'],
            $this->granted($this->scopedOwner()),
        );
    }

    public function test_only_naming_unregistered_abilities_grants_nothing(): void
    {
        $this->configure(['using' => true, 'only' => ['ghost', 'posts.publish']]);

        $owner = $this->scopedOwner();

        $this->assertSame([], $this->granted($owner));
        $this->assertSame([], $this->shared($owner));
    }

    public function test_a_platform_ability_is_never_granted_even_when_only_names_it(): void
    {
        $this->configure(['using' => true, 'only' => ['users.impersonate', 'users']]);

        $owner = $this->scopedOwner();

        $this->assertSame(['users.export'], $this->granted($owner));
        $this->assertSame(['users.export'], $this->shared($owner));
    }

    public function test_a_gate_define_on_an_excluded_key_still_passes_and_is_shared(): void
    {
        $this->configure(['using' => true, 'except' => ['settings']]);
        Gate::define('settings.manage', static fn (): bool => true);

        $owner = $this->scopedOwner();

        $this->assertTrue(Gate::forUser($owner)->allows('settings.manage'));
        $this->assertContains('settings.manage', $this->shared($owner));
    }

    public function test_a_gate_define_on_a_platform_key_still_passes_and_is_shared(): void
    {
        Gate::define('users.impersonate', static fn (): bool => true);

        $owner = $this->scopedOwner();

        $this->assertTrue(Gate::forUser($owner)->allows('users.impersonate'));
        $this->assertContains('users.impersonate', $this->shared($owner));
    }

    public function test_a_stored_row_for_an_excluded_key_is_granted_and_shared(): void
    {
        $this->configure(['using' => true, 'except' => ['settings']]);

        $owner = $this->scopedOwner();
        $owner->givePermissionTo('settings.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $owner->unsetRelation('permissions');

        $this->assertTrue(Gate::forUser($owner)->allows('settings.manage'));
        $this->assertContains('settings.manage', $this->shared($owner));
    }

    public function test_scalar_only_and_except_are_read_as_one_pattern(): void
    {
        $this->configure(['using' => true, 'only' => 'posts', 'except' => 'posts.delete']);

        $this->assertSame(
            ['posts.viewAny', 'posts.view', 'posts.create', 'posts.update'],
            $this->granted($this->scopedOwner()),
        );
    }

    public function test_a_false_only_grants_nothing(): void
    {
        $this->configure(['using' => true, 'only' => false]);

        $owner = $this->scopedOwner();

        $this->assertTrue(TeamOwner::enabled());
        $this->assertSame([], $this->granted($owner));
        $this->assertSame([], $this->shared($owner));
    }

    public function test_an_unknown_class_string_as_using_fails_closed(): void
    {
        $this->configure(['using' => 'App\\Kinetix\\NoSuchOwnerRule']);

        $owner = $this->scopedOwner();

        $this->assertFalse(TeamOwner::enabled());
        $this->assertSame([], $this->granted($owner));
        $this->assertSame([], $this->shared($owner));
    }

    public function test_a_null_using_is_off(): void
    {
        $this->configure(['using' => null, 'except' => ['settings']]);

        $owner = $this->scopedOwner();

        $this->assertFalse(TeamOwner::enabled());
        $this->assertSame([], $this->granted($owner));
        $this->assertSame([], $this->shared($owner));
    }

    public function test_an_empty_array_is_off(): void
    {
        $this->configure([]);

        $owner = $this->scopedOwner();

        $this->assertFalse(TeamOwner::enabled());
        $this->assertSame([], $this->granted($owner));
        $this->assertSame([], $this->shared($owner));
    }

    public function test_a_structured_array_without_using_is_off(): void
    {
        $this->configure(['except' => ['settings']]);

        $this->assertFalse(TeamOwner::enabled());
        $this->assertSame([], $this->granted($this->scopedOwner()));
    }

    public function test_the_owner_of_team_a_scoped_to_team_b_gets_nothing(): void
    {
        $owner = $this->scopedOwner();

        $other = ScopeTeam::create(['owner_id' => 999]);
        $owner->teams()->attach($other->getKey());

        app(PermissionRegistrar::class)->setPermissionsTeamId($other->getKey());

        $this->assertSame([], $this->granted($owner));
        $this->assertSame([], $this->shared($owner));
    }

    public function test_the_super_admin_is_unaffected_by_except(): void
    {
        $this->configure(['using' => true, 'except' => ['settings', 'posts']]);

        $team = ScopeTeam::create(['owner_id' => 999]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($team->getKey());

        $root = ScopeUser::create(['name' => 'Root']);
        $root->assignRole(Role::findOrCreate('super-admin', 'web'));

        $this->assertTrue(Gate::forUser($root)->allows('settings.manage'));
        $this->assertTrue(Gate::forUser($root)->allows('posts.delete'));
        $this->assertTrue(Gate::forUser($root)->allows('users.impersonate'));
        $this->assertTrue($this->sharedPayload($root)['isSuperAdmin']);
    }

    public function test_the_acceptance_config_needs_no_host_gate_before(): void
    {
        $this->configure(['using' => true, 'except' => ['users', 'settings']]);

        $owner    = $this->scopedOwner();
        $expected = $this->everyRegisteredBut(['users.export', 'users.impersonate', 'settings.manage']);

        $this->assertSame($expected, $this->granted($owner));
        $this->assertSame($expected, $this->shared($owner));
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function parityMatrix(): array
    {
        $configs = [
            'legacy true'         => true,
            'legacy callable'     => [ScopeOwnership::class, 'check'],
            'structured true'     => ['using' => true],
            'structured callable' => ['using' => [ScopeOwnership::class, 'check']],
            'except features'     => ['using' => true, 'except' => ['users', 'settings']],
            'except keys'         => ['using' => true, 'except' => ['posts.delete', 'roles.manage']],
            'except feature star' => ['using' => true, 'except' => ['posts.*']],
            'only'                => ['using' => true, 'only' => ['posts']],
            'only and except'     => ['using' => true, 'only' => ['posts', 'settings'], 'except' => ['posts.delete']],
            'only unregistered'   => ['using' => true, 'only' => ['ghost']],
            'only platform'       => ['using' => true, 'only' => ['users.impersonate']],
            'off'                 => ['using' => null],
        ];

        $cases = [];

        foreach ($configs as $label => $config) {
            foreach (['auto', 'sweep'] as $mode) {
                $cases["{$label} / {$mode}"] = [$config, $mode];
            }
        }

        return $cases;
    }

    #[DataProvider('parityMatrix')]
    public function test_the_gate_and_the_prop_agree_on_every_registered_ability(mixed $config, string $mode): void
    {
        $this->configure($config);
        config()->set('kinetix.permissions.dynamic_grants', $mode);

        // A grant outside the bypass, on a key some configs exclude.
        Gate::define('settings.manage', static fn (): bool => true);

        $owner  = $this->scopedOwner();
        $shared = $this->shared($owner);
        $gate   = Gate::forUser($owner);

        foreach (app(PermissionRegistry::class)->allPermissions() as $ability) {
            $this->assertSame(
                $gate->allows($ability),
                in_array($ability, $shared, true),
                "server and SPA disagree on {$ability}",
            );
        }
    }

    public function test_an_owner_costs_no_gate_check_in_auto_mode(): void
    {
        $this->configure(['using' => true, 'except' => ['settings']]);

        $checks = 0;
        Gate::after(static function () use (&$checks): void {
            $checks++;
        });

        $owner = $this->scopedOwner();
        $this->shared($owner);

        $this->assertSame(0, $checks);
    }

    public function test_sweep_mode_only_asks_the_gate_about_abilities_outside_the_scope(): void
    {
        $this->configure(['using' => true, 'except' => ['settings']]);
        config()->set('kinetix.permissions.dynamic_grants', 'sweep');

        $checks = [];
        Gate::after(static function ($user, string $ability) use (&$checks): void {
            $checks[] = $ability;
        });

        $this->shared($this->scopedOwner());

        $this->assertEqualsCanonicalizing(['settings.manage', 'users.impersonate'], $checks);
    }

    /**
     * Set the option at runtime. The Gate::before registered at boot reads the
     * scope and the ownership rule on every call, so this takes effect at once.
     */
    private function configure(mixed $ownerBypass): void
    {
        config()->set('kinetix.permissions.owner_bypass', $ownerBypass);
        TeamOwner::flush();
    }

    /**
     * An owner of a team, with the permission layer scoped to that team.
     */
    private function scopedOwner(): ScopeUser
    {
        $owner = ScopeUser::create(['name' => 'Owner']);
        $team  = ScopeTeam::create(['owner_id' => $owner->getKey()]);

        $owner->teams()->attach($team->getKey());
        app(PermissionRegistrar::class)->setPermissionsTeamId($team->getKey());

        return $owner;
    }

    /**
     * The registered abilities the Gate grants, in registry order.
     *
     * @return array<int, string>
     */
    private function granted(ScopeUser $user): array
    {
        $gate = Gate::forUser($user);

        return array_values(array_filter(
            app(PermissionRegistry::class)->allPermissions(),
            static fn (string $ability): bool => $gate->allows($ability),
        ));
    }

    /**
     * The `kinetix_permissions.permissions` list, in registry order.
     *
     * @return array<int, string>
     */
    private function shared(ScopeUser $user): array
    {
        $shared = $this->sharedPayload($user)['permissions'];

        return array_values(array_filter(
            app(PermissionRegistry::class)->allPermissions(),
            static fn (string $ability): bool => in_array($ability, $shared, true),
        ));
    }

    /**
     * @return array{enabled: bool, permissions: array<int, string>, roles: array<int, string>, isSuperAdmin: bool}
     */
    private function sharedPayload(ScopeUser $user): array
    {
        $this->actingAs($user);

        /** @var callable $shared */
        $shared = Inertia::getShared('kinetix_permissions');

        return value($shared);
    }

    /**
     * @param  array<int, string> $excluded
     * @return array<int, string>
     */
    private function everyRegisteredBut(array $excluded): array
    {
        return array_values(array_diff(app(PermissionRegistry::class)->allPermissions(), $excluded));
    }
}
