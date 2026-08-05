<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Inertia;

class TeamScopedUser extends Authenticatable
{
    use Notifiable;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * Stand-in for the starter kit's `currentTeam` relation — enough for
     * KinetixTeams::currentTeamKey() to resolve a primary key.
     */
    public function getCurrentTeamAttribute(): Model
    {
        $team = new class extends Model {};
        $team->setAttribute('id', 7);

        return $team;
    }
}

class NotificationTeamScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    private function seedNotification(TeamScopedUser $user, array $data): void
    {
        $user->notifications()->create([
            'id'   => (string) Str::uuid(),
            'type' => 'kinetix',
            'data' => $data,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sharedNotifications(): array
    {
        $shared = Inertia::getShared('kinetix_notifications');

        return (array) $shared();
    }

    public function test_the_bell_lists_only_the_active_teams_and_global_notifications_when_team_scoped(): void
    {
        config()->set('kinetix.teams', true);
        config()->set('kinetix.notifications.database', true);

        $user = TeamScopedUser::create(['name' => 'A']);
        $this->seedNotification($user, ['title' => 'mine', 'team' => 7]);
        $this->seedNotification($user, ['title' => 'other team', 'team' => 9]);
        $this->seedNotification($user, ['title' => 'global']);

        $this->actingAs($user);

        $titles = array_column($this->sharedNotifications(), 'title');
        sort($titles);

        $this->assertSame(['global', 'mine'], $titles);
    }

    public function test_the_bell_lists_everything_when_notifications_are_not_team_scoped(): void
    {
        config()->set('kinetix.teams', true);
        // Per-module override wins over the inherited global switch.
        config()->set('kinetix.notifications.teams', false);
        config()->set('kinetix.notifications.database', true);

        $user = TeamScopedUser::create(['name' => 'A']);
        $this->seedNotification($user, ['title' => 'mine', 'team' => 7]);
        $this->seedNotification($user, ['title' => 'other team', 'team' => 9]);

        $this->actingAs($user);

        $this->assertCount(2, $this->sharedNotifications());
    }

    public function test_kinetix_config_shares_the_poll_interval_and_team_id(): void
    {
        config()->set('kinetix.teams', true);
        config()->set('kinetix.notifications.poll', 15000);

        $user = TeamScopedUser::create(['name' => 'A']);
        $this->actingAs($user);

        $config = (array) Inertia::getShared('kinetix_config')();

        $this->assertSame(15000, $config['poll']);
        // Teams are on and notifications inherit → the active team's PRIMARY key.
        $this->assertSame(7, $config['team_id']);
    }

    public function test_team_id_is_null_when_notifications_are_not_team_scoped(): void
    {
        config()->set('kinetix.notifications.teams', false);

        $user = TeamScopedUser::create(['name' => 'A']);
        $this->actingAs($user);

        $config = (array) Inertia::getShared('kinetix_config')();

        $this->assertNull($config['team_id']);
    }
}
