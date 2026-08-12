<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Happones\Kinetix\Announcements\Announcement;
use Happones\Kinetix\Announcements\AnnouncementManager;
use Happones\Kinetix\Announcements\KinetixAnnouncements;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;

class AnnouncementUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class AnnouncementsTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.announcements.enabled', true);
        $app['config']->set('auth.providers.users.model', AnnouncementUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });
        Schema::create('kinetix_announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->string('level')->default('info');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
        Schema::create('kinetix_announcement_views', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();
        });
        Schema::create('kinetix_announcement_dismissals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('announcement_id');
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'announcement_id']);
        });
    }

    private function user(): AnnouncementUser
    {
        return AnnouncementUser::create(['name' => 'Ada']);
    }

    public function test_feed_lists_published_only_and_marks_all_new_for_a_fresh_user(): void
    {
        KinetixAnnouncements::publish('Launched', 'Body', 'feature');
        Announcement::create(['title' => 'Draft', 'body' => 'x', 'published_at' => null]);
        Announcement::create(['title' => 'Future', 'body' => 'x', 'published_at' => now()->addDay()]);

        $this->actingAs($this->user())
            ->getJson('/_kinetix/announcements')
            ->assertOk()
            ->assertJsonCount(1, 'announcements')
            ->assertJsonPath('announcements.0.title', 'Launched')
            ->assertJsonPath('announcements.0.isNew', true)
            ->assertJsonPath('unread', 1);
    }

    public function test_marking_seen_clears_unread_and_isnew(): void
    {
        $user = $this->user();
        KinetixAnnouncements::publish('One', 'a');

        $this->actingAs($user)->postJson('/_kinetix/announcements/seen')->assertOk();

        $this->actingAs($user)
            ->getJson('/_kinetix/announcements')
            ->assertOk()
            ->assertJsonPath('unread', 0)
            ->assertJsonPath('announcements.0.isNew', false);
    }

    public function test_publish_accepts_both_carbon_and_carbon_immutable(): void
    {
        $mutable   = KinetixAnnouncements::publish('A', 'x', 'info', Carbon::parse('2026-06-01'));
        $immutable = KinetixAnnouncements::publish('B', 'x', 'info', CarbonImmutable::parse('2026-06-02'));

        $this->assertSame('2026-06-01', $mutable->published_at->format('Y-m-d'));
        $this->assertSame('2026-06-02', $immutable->published_at->format('Y-m-d'));
    }

    public function test_the_banner_serves_undismissed_entries_only(): void
    {
        $user = $this->user();
        $keep = KinetixAnnouncements::publish('Keep', 'a', 'feature', now()->subMinute());
        $drop = KinetixAnnouncements::publish('Drop', 'b', 'info', now()->subMinutes(2));

        $this->actingAs($user)
            ->getJson('/_kinetix/announcements/banner')
            ->assertOk()
            ->assertJsonCount(2, 'announcements');

        $this->actingAs($user)
            ->postJson("/_kinetix/announcements/{$drop->getKey()}/dismiss")
            ->assertOk();

        $this->actingAs($user)
            ->getJson('/_kinetix/announcements/banner')
            ->assertOk()
            ->assertJsonCount(1, 'announcements')
            ->assertJsonPath('announcements.0.id', $keep->getKey());
    }

    public function test_dismissing_hides_the_banner_entry_without_marking_the_feed_read(): void
    {
        $user = $this->user();
        KinetixAnnouncements::publish('One', 'a');

        $announcement = KinetixAnnouncements::publish('Two', 'b');

        $this->actingAs($user)
            ->postJson("/_kinetix/announcements/{$announcement->getKey()}/dismiss")
            ->assertOk();

        // The feed is untouched: dismissing is "hide this banner", not "I read
        // everything".
        $this->actingAs($user)
            ->getJson('/_kinetix/announcements')
            ->assertOk()
            ->assertJsonCount(2, 'announcements')
            ->assertJsonPath('unread', 2);
    }

    public function test_the_banner_can_be_narrowed_to_levels_and_a_limit(): void
    {
        $user = $this->user();
        KinetixAnnouncements::publish('Feature', 'a', 'feature', now()->subMinute());
        KinetixAnnouncements::publish('Fix', 'b', 'fix', now()->subMinutes(2));
        KinetixAnnouncements::publish('Info', 'c', 'info', now()->subMinutes(3));

        $this->actingAs($user)
            ->getJson('/_kinetix/announcements/banner?levels=feature,fix')
            ->assertOk()
            ->assertJsonCount(2, 'announcements');

        $this->actingAs($user)
            ->getJson('/_kinetix/announcements/banner?limit=1')
            ->assertOk()
            ->assertJsonCount(1, 'announcements')
            ->assertJsonPath('announcements.0.title', 'Feature');
    }

    public function test_the_banner_never_serves_drafts_or_scheduled_entries(): void
    {
        Announcement::create(['title' => 'Draft', 'body' => 'x', 'published_at' => null]);
        Announcement::create(['title' => 'Future', 'body' => 'x', 'published_at' => now()->addDay()]);

        $this->actingAs($this->user())
            ->getJson('/_kinetix/announcements/banner')
            ->assertOk()
            ->assertJsonCount(0, 'announcements');
    }

    public function test_announcements_published_after_seen_become_new_again(): void
    {
        $user    = $this->user();
        $manager = app(AnnouncementManager::class);

        KinetixAnnouncements::publish('Old', 'a', 'info', now()->subDays(2));
        $manager->markSeen($user);
        $this->assertSame(0, $manager->unreadCount($user));

        // A new announcement published after the user last looked is unread again.
        $this->travel(1)->minute();
        KinetixAnnouncements::publish('Fresh', 'b');

        $this->assertSame(1, $manager->unreadCount($user));
    }
}
