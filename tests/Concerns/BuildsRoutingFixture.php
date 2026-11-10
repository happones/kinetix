<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Concerns;

use Happones\Kinetix\NotificationRouting\AudienceType;
use Happones\Kinetix\NotificationRouting\NotificationGroup;
use Happones\Kinetix\NotificationRouting\NotificationGroupMember;
use Happones\Kinetix\NotificationRouting\NotificationRoute;
use Happones\Kinetix\Permissions\KinetixPermissions;
use Happones\Kinetix\Tests\Fakes\RoutingTeam;
use Happones\Kinetix\Tests\Fakes\RoutingUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The tables and helpers the notification routing tests share: teams with
 * members, spatie roles, the preferences, routing and notifications tables.
 */
trait BuildsRoutingFixture
{
    protected function createRoutingTables(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });
        Schema::create('teams', function (Blueprint $table) {
            $table->increments('id');
            $table->string('slug')->unique();
        });
        Schema::create('routing_clients', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });
        Schema::create('team_members', function (Blueprint $table) {
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('team_id');
        });
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        foreach (['permissions', 'roles'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
        }
        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
        });
        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
        });
        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
        });

        foreach ([
            '2026_01_01_000012_create_kinetix_notification_preferences_table.php',
            '2026_01_01_000039_add_notifiable_type_to_kinetix_notification_preferences_table.php',
            '2026_01_01_000040_scope_kinetix_notification_preferences_unique_to_type.php',
            '2026_01_01_000054_create_kinetix_notification_groups_tables.php',
            '2026_01_01_000055_create_kinetix_notification_routes_table.php',
        ] as $migration) {
            (require __DIR__.'/../../database/migrations/'.$migration)->up();
        }

        foreach (KinetixPermissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }

    protected function team(string $slug): RoutingTeam
    {
        return RoutingTeam::query()->create(['slug' => $slug]);
    }

    protected function member(RoutingTeam $team, string $name): RoutingUser
    {
        $user = RoutingUser::query()->create(['name' => $name]);
        $user->teams()->attach($team);

        return $user;
    }

    protected function admin(RoutingTeam $team): RoutingUser
    {
        $user = $this->member($team, 'Admin of '.$team->slug);
        $user->givePermissionTo('notification_routing.manage');

        return $user;
    }

    protected function ref(RoutingUser $user): string
    {
        return $user->getMorphClass().'|'.$user->getKey();
    }

    protected function role(string $name): Role
    {
        return Role::findOrCreate($name, 'web');
    }

    protected function route(RoutingTeam $team, string $type, AudienceType $audience, string|int|null $id, array $channels): NotificationRoute
    {
        // A member rule names its person by member ref.
        if ($audience === AudienceType::Member && $id !== null && ! str_contains((string) $id, '|')) {
            $id = $this->ref(RoutingUser::query()->findOrFail($id));
        }

        return NotificationRoute::query()->create([
            'team_id'       => $team->getKey(),
            'type'          => $type,
            'audience_type' => $audience,
            'audience_id'   => $id === null ? '' : (string) $id,
            'channels'      => $channels,
        ]);
    }

    /**
     * @param list<RoutingUser> $members
     */
    protected function group(RoutingTeam $team, string $name, array $members = []): NotificationGroup
    {
        $group = NotificationGroup::query()->create(['team_id' => $team->getKey(), 'name' => $name]);

        foreach ($members as $user) {
            NotificationGroupMember::query()->create(['group_id' => $group->getKey(), 'user_type' => $user->getMorphClass(), 'user_id' => $user->getKey()]);
        }

        return $group;
    }
}
