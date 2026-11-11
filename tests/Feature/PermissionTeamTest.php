<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Permissions\PermissionTeam;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;

class PermissionTeamTestUser extends Model
{
    protected $guarded = [];
}

class PermissionTeamTest extends TestCase
{
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
        $app['config']->set('permission.teams', true);
    }

    private function userWithLoadedRoles(): PermissionTeamTestUser
    {
        return (new PermissionTeamTestUser)->setRelation('roles', collect())->setRelation('permissions', collect());
    }

    public function test_it_pins_the_team_and_restores_it(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(5);
        $user = $this->userWithLoadedRoles();

        $seen = PermissionTeam::run(9, function () use ($registrar, $user): mixed {
            $this->assertFalse($user->relationLoaded('roles'));
            $this->assertFalse($user->relationLoaded('permissions'));

            return $registrar->getPermissionsTeamId();
        }, $user);

        $this->assertSame(9, $seen);
        $this->assertSame(5, $registrar->getPermissionsTeamId());
    }

    public function test_pin_drops_the_users_loaded_roles_and_reads_under_the_pinned_team(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(8);
        $user = $this->userWithLoadedRoles();

        $seen = PermissionTeam::pin(7, function () use ($registrar, $user): array {
            return [$registrar->getPermissionsTeamId(), $user->relationLoaded('roles'), PermissionTeam::pinnedKey()];
        }, $user);

        $this->assertSame([7, false, 7], $seen);
        $this->assertSame(8, $registrar->getPermissionsTeamId());
        $this->assertNull(PermissionTeam::pinnedKey());
    }

    public function test_an_exception_in_the_callback_still_restores_the_team(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(5);
        $user = $this->userWithLoadedRoles();

        try {
            PermissionTeam::run(9, static function (): never {
                throw new RuntimeException('boom');
            }, $user);
            $this->fail('The exception was swallowed.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(5, $registrar->getPermissionsTeamId());
        $this->assertFalse($user->relationLoaded('roles'));
    }

    public function test_without_spatie_teams_it_just_runs_the_callback(): void
    {
        config()->set('permission.teams', false);
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(5);

        $this->assertSame(5, PermissionTeam::run(9, fn () => $registrar->getPermissionsTeamId()));
    }
}
