<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Announcements\Announcement;
use Happones\Kinetix\Announcements\AnnouncementManager;
use Happones\Kinetix\Announcements\KinetixAnnouncements;
use Happones\Kinetix\Tests\Concerns\ResolvesTeamRoutes;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionServiceProvider;

class FeedUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * Announcements were one global pool. They now belong to the team they were
 * published from, with `team_id` NULL reserved for platform-wide entries — the
 * "what's new" case, which is published from a deploy step with no team context.
 */
class AnnouncementTeamScopingTest extends TestCase
{
    use ResolvesTeamRoutes;

    /**
     * @param  Application              $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        // Teams put the permission-team middleware on the routes.
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
        $app['config']->set('kinetix.announcements.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        (require __DIR__.'/../../database/migrations/2026_01_01_000014_create_kinetix_announcements_table.php')->up();
        (require __DIR__.'/../../database/migrations/2026_01_01_000025_add_team_id_to_kinetix_announcements_table.php')->up();
        (require __DIR__.'/../../database/migrations/2026_01_01_000028_add_team_id_to_kinetix_announcement_views_table.php')->up();
        (require __DIR__.'/../../database/migrations/2026_01_01_000029_create_kinetix_announcement_dismissals_table.php')->up();
        (require __DIR__.'/../../database/migrations/2026_01_01_000031_add_expires_at_to_kinetix_announcements_table.php')->up();

        $this->withTeamSegment(7);
    }

    private function announce(?int $teamId, string $title): Announcement
    {
        return Announcement::create([
            'team_id'      => $teamId,
            'title'        => $title,
            'body'         => 'Body',
            'level'        => 'info',
            'published_at' => now()->subMinute(),
        ]);
    }

    private function user(): FeedUser
    {
        return FeedUser::query()->firstOrCreate(['id' => 1], ['name' => 'Reader']);
    }

    public function test_the_feed_shows_the_teams_own_entries_and_the_global_ones(): void
    {
        $this->announce(null, 'Platform update');
        $this->announce(7, 'Ours');
        $this->announce(8, 'Theirs');

        $titles = array_map(
            static fn (object $a): string => $a->title,
            app(AnnouncementManager::class)->feed($this->user()),
        );

        sort($titles);

        $this->assertSame(['Ours', 'Platform update'], $titles);
    }

    public function test_the_unread_count_excludes_other_teams(): void
    {
        $this->announce(null, 'Platform update');
        $this->announce(7, 'Ours');
        $this->announce(8, 'Theirs');

        $this->assertSame(2, app(AnnouncementManager::class)->unreadCount($this->user()));
    }

    public function test_publishing_belongs_to_the_active_team(): void
    {
        $announcement = KinetixAnnouncements::publish('Ours', 'Body');

        $this->assertSame(7, (int) $announcement->team_id);
        $this->assertFalse($announcement->isGlobal());
    }

    public function test_publishing_globally_reaches_every_team(): void
    {
        $global = KinetixAnnouncements::publishGlobally('Platform update', 'Body');

        $this->assertNull($global->team_id);
        $this->assertTrue($global->isGlobal());

        foreach ([7, 8] as $teamId) {
            $this->withTeamSegment($teamId);

            $this->assertSame(
                ['Platform update'],
                array_map(
                    static fn (object $a): string => $a->title,
                    app(AnnouncementManager::class)->feed($this->user()),
                ),
            );
        }
    }

    public function test_a_deploy_step_without_team_context_publishes_globally(): void
    {
        // No request, no currentTeam — `publish()` must not silently attach the
        // entry to a team nobody is in.
        $this->withoutTeamSegment();

        $this->assertNull(KinetixAnnouncements::publish('From the deploy', 'Body')->team_id);
    }

    public function test_reading_one_teams_feed_leaves_the_other_teams_badge_alone(): void
    {
        $this->announce(7, 'Ours');
        $this->announce(8, 'Theirs');

        $manager = app(AnnouncementManager::class);
        $manager->markSeen($this->user());

        $this->assertSame(0, $manager->unreadCount($this->user()));

        // The same user, one team over: their own entries are still unread.
        $this->withTeamSegment(8);

        $this->assertSame(1, $manager->unreadCount($this->user()));
    }

    public function test_reading_the_platform_wide_entries_clears_them_in_every_team(): void
    {
        $this->announce(null, 'Platform update');
        $this->announce(8, 'Theirs');

        $manager = app(AnnouncementManager::class);
        $manager->markSeen($this->user());

        // Read once, read everywhere — the global pool tracks its own state, so
        // it doesn't follow the user from team to team.
        $this->withTeamSegment(8);

        $this->assertSame(1, $manager->unreadCount($this->user()));
        $this->assertEqualsCanonicalizing(
            ['Platform update' => false, 'Theirs' => true],
            array_reduce(
                $manager->feed($this->user()),
                static function (array $carry, object $a): array {
                    $carry[$a->title] = $a->isNew;

                    return $carry;
                },
                [],
            ),
        );
    }

    public function test_the_banner_hides_dismissed_entries_and_refuses_another_teams_id(): void
    {
        $ours   = $this->announce(7, 'Ours');
        $theirs = $this->announce(8, 'Theirs');

        $this->actingAs($this->user())
            ->postJson("/7/_kinetix/announcements/{$ours->getKey()}/dismiss")
            ->assertOk();

        $this->actingAs($this->user())
            ->getJson('/7/_kinetix/announcements/banner')
            ->assertOk()
            ->assertJsonCount(0, 'announcements');

        $this->actingAs($this->user())
            ->postJson("/7/_kinetix/announcements/{$theirs->getKey()}/dismiss")
            ->assertNotFound();
    }

    public function test_the_endpoint_serves_the_scoped_feed(): void
    {
        $this->announce(null, 'Platform update');
        $this->announce(8, 'Theirs');

        $this->actingAs($this->user())
            ->getJson('/7/_kinetix/announcements')
            ->assertOk()
            ->assertJsonCount(1, 'announcements')
            ->assertJsonPath('announcements.0.title', 'Platform update')
            ->assertJsonPath('unread', 1);
    }
}
