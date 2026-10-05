<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Announcements\AnnouncementController;
use Happones\Kinetix\Announcements\AnnouncementManager;
use Happones\Kinetix\Announcements\KinetixAnnouncements;
use Happones\Kinetix\Data\AnnouncementData;
use Happones\Kinetix\KinetixServiceProvider;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;

class AnnouncementDisplayUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * What an announcement shows beyond its text: the level's color and icon
 * (`kinetix.announcements.levels`), whether it may be closed, and a call to
 * action. Runs against the real migrations.
 */
class AnnouncementDisplayTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.announcements.enabled', true);
        $app['config']->set('auth.providers.users.model', AnnouncementDisplayUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        foreach ([
            '2026_01_01_000014_create_kinetix_announcements_table.php',
            '2026_01_01_000029_create_kinetix_announcement_dismissals_table.php',
            '2026_01_01_000031_add_expires_at_to_kinetix_announcements_table.php',
            '2026_01_01_000037_add_display_fields_to_kinetix_announcements_table.php',
        ] as $file) {
            (require __DIR__.'/../../database/migrations/'.$file)->up();
        }
    }

    private function user(): AnnouncementDisplayUser
    {
        return AnnouncementDisplayUser::query()->create(['name' => 'Ana']);
    }

    private function bannerEntry(): AnnouncementData
    {
        return app(AnnouncementManager::class)->banner($this->user())[0];
    }

    public function test_an_entry_carries_its_level_color_and_icon(): void
    {
        KinetixAnnouncements::publish('Dark mode', 'Toggle it from the header.', 'feature');

        $entry = $this->bannerEntry();

        $this->assertSame('success', $entry->color);
        $this->assertSame('sparkles', $entry->icon);
    }

    public function test_levels_come_from_config_and_unknown_ones_read_neutral(): void
    {
        config()->set('kinetix.announcements.levels', [
            'maintenance' => ['color' => 'warning', 'icon' => 'wrench'],
            'odd'         => ['color' => 'chartreuse'],
        ]);

        KinetixAnnouncements::publish('Down tonight', '02:00–04:00 UTC.', 'maintenance');
        $this->assertSame('warning', $this->bannerEntry()->color);

        KinetixAnnouncements::publish('Odd', '…', 'odd');
        KinetixAnnouncements::publish('From code', '…', 'not-configured');

        $colors = [];

        foreach (app(AnnouncementManager::class)->banner($this->user(), 10) as $entry) {
            $colors[$entry->title] = $entry->color;
        }

        ksort($colors);

        $this->assertSame(['Down tonight' => 'warning', 'From code' => 'gray', 'Odd' => 'gray'], $colors);
    }

    public function test_an_entry_can_refuse_to_be_closed_and_carry_a_button(): void
    {
        KinetixAnnouncements::publish(
            'Rotate your API keys',
            'A provider leaked tokens.',
            'fix',
            dismissible: false,
            actionLabel: 'Rotate now',
            actionUrl: '/settings/tokens',
        );

        $entry = $this->bannerEntry();

        $this->assertFalse($entry->dismissible);
        $this->assertSame('Rotate now', $entry->actionLabel);
        $this->assertSame('/settings/tokens', $entry->actionUrl);
    }

    public function test_the_body_ships_as_safe_html_rendered_from_markdown(): void
    {
        KinetixAnnouncements::publish(
            'Exports',
            "Now **3× faster**.\nSee [the guide](/docs/export).\n\n<script>alert(1)</script>",
        );

        $html = (string) $this->bannerEntry()->bodyHtml;

        $this->assertStringContainsString('<strong>3× faster</strong>', $html);
        // A plain newline still breaks the line, as the text field did.
        $this->assertStringContainsString('<br />', $html);
        $this->assertStringContainsString('<a href="/docs/export">the guide</a>', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_markdown_can_be_turned_off(): void
    {
        config()->set('kinetix.announcements.markdown', false);
        KinetixAnnouncements::publish('Plain', '**as typed**');

        $entry = $this->bannerEntry();

        $this->assertNull($entry->bodyHtml);
        $this->assertSame('**as typed**', $entry->body);
    }

    public function test_a_plain_entry_stays_closable_without_a_button(): void
    {
        KinetixAnnouncements::publish('v2', 'Faster search.');

        $entry = $this->bannerEntry();

        $this->assertTrue($entry->dismissible);
        $this->assertNull($entry->actionLabel);
        $this->assertNull($entry->actionUrl);
    }

    public function test_the_editor_saves_the_new_fields_and_offers_the_levels(): void
    {
        Gate::define('manageKinetixAnnouncements', fn (): bool => true);
        $user = $this->user();

        $this->actingAs($user)
            ->postJson('/_kinetix/announcements', [
                'title'        => 'Security notice',
                'body'         => 'Rotate your keys.',
                'level'        => 'fix',
                'published_at' => now()->toIso8601String(),
                'dismissible'  => false,
                'action_label' => 'Read more',
                'action_url'   => 'https://status.example.com/incident',
            ])
            ->assertCreated()
            ->assertJsonPath('announcement.dismissible', false)
            ->assertJsonPath('announcement.actionUrl', 'https://status.example.com/incident');

        $this->actingAs($user)
            ->getJson('/_kinetix/announcements/manage')
            ->assertOk()
            ->assertJsonPath('levels.1', ['value' => 'feature', 'color' => 'success', 'icon' => 'sparkles']);
    }

    public function test_a_button_needs_both_its_label_and_its_url(): void
    {
        Gate::define('manageKinetixAnnouncements', fn (): bool => true);

        $this->actingAs($this->user())
            ->postJson('/_kinetix/announcements', [
                'title'        => 'Half a button',
                'body'         => '…',
                'level'        => 'info',
                'action_label' => 'Go',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('action_url');
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function actionUrls(): array
    {
        return [
            'app path'           => ['/docs/new-export', true],
            'https link'         => ['https://example.com/guide', true],
            'http link'          => ['http://example.com', true],
            'javascript'         => ['javascript:alert(1)', false],
            'data'               => ['data:text/html,<script>1</script>', false],
            'protocol-relative'  => ['//evil.example.com', false],
            'backslash trick'    => ['/\\evil.example.com', false],
            'relative, no slash' => ['docs/x', false],
        ];
    }

    #[DataProvider('actionUrls')]
    public function test_only_app_paths_and_http_links_are_accepted(string $url, bool $accepted): void
    {
        $this->assertSame($accepted, AnnouncementController::isSafeActionUrl($url));
    }

    public function test_the_display_migration_is_idempotent_and_published(): void
    {
        (require __DIR__.'/../../database/migrations/2026_01_01_000037_add_display_fields_to_kinetix_announcements_table.php')->up();

        $this->assertTrue(Schema::hasColumns('kinetix_announcements', ['dismissible', 'action_label', 'action_url']));

        $published = array_map(
            'basename',
            array_values(ServiceProvider::pathsToPublish(KinetixServiceProvider::class, 'kinetix-announcements-migrations')),
        );

        $this->assertContains('2026_01_01_000037_add_display_fields_to_kinetix_announcements_table.php', $published);
    }
}
