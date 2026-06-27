<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Presence\PresenceManager;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;

class PresenceTeam extends Model
{
    protected $table = 'teams';

    public $timestamps = false;

    protected $guarded = [];
}

class PresenceUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class PresenceTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.presence.enabled', true);
        $app['config']->set('kinetix.presence.channel', 'kinetix-presence');
        $app['config']->set('auth.providers.users.model', PresenceUser::class);
    }

    public function test_channel_name_is_the_base_without_teams(): void
    {
        $this->assertSame('kinetix-presence', app(PresenceManager::class)->channelName());
        $this->assertSame('kinetix-presence', app(PresenceManager::class)->channelPattern());
    }

    public function test_channel_is_team_suffixed_when_teams_are_on(): void
    {
        config()->set('kinetix.teams', true);

        $team = new PresenceTeam(['id' => 7]);
        $user = new PresenceUser(['id' => 1]);
        $user->setRelation('currentTeam', $team);
        $this->actingAs($user);

        $this->assertSame('kinetix-presence.7', app(PresenceManager::class)->channelName());
        $this->assertSame('kinetix-presence.{team}', app(PresenceManager::class)->channelPattern());
    }

    public function test_member_data_exposes_id_name_and_avatar(): void
    {
        config()->set('kinetix.presence.avatar_attribute', 'avatar_url');

        $user = new PresenceUser(['id' => 3, 'name' => 'Ada', 'avatar_url' => 'https://x/a.png']);

        $this->assertSame(
            ['id' => 3, 'name' => 'Ada', 'avatar' => 'https://x/a.png'],
            app(PresenceManager::class)->memberData($user),
        );
    }

    public function test_member_data_avatar_is_null_when_missing(): void
    {
        $user = new PresenceUser(['id' => 4, 'name' => 'Grace']);

        $this->assertNull(app(PresenceManager::class)->memberData($user)['avatar']);
    }

    public function test_state_is_disabled_when_off(): void
    {
        config()->set('kinetix.presence.enabled', false);

        $this->assertSame(
            ['enabled' => false, 'channel' => null],
            app(PresenceManager::class)->state(),
        );
    }

    public function test_state_carries_the_channel_when_on(): void
    {
        $state = app(PresenceManager::class)->state();

        $this->assertTrue($state['enabled']);
        $this->assertSame('kinetix-presence', $state['channel']);
    }
}
