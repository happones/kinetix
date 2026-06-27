<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

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
