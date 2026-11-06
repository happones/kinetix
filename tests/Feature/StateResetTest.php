<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Announcements\AnnouncementManager;
use Happones\Kinetix\Help\HelpManager;
use Happones\Kinetix\Settings\KinetixSettings;
use Happones\Kinetix\Settings\Setting;
use Happones\Kinetix\Settings\SettingsManager;
use Happones\Kinetix\Support\KinetixStateReset;
use Happones\Kinetix\Support\KinetixTeams;
use Happones\Kinetix\Tests\Concerns\CountsKinetixQueries;
use Happones\Kinetix\Tests\Concerns\ResolvesTeamRoutes;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use ReflectionProperty;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;

class StateResetNoopJob implements ShouldQueue
{
    public function handle(): void {}
}

/**
 * Stands in for Octane's `RequestReceived` when Octane isn't installed: the
 * provider listens for it when the class exists.
 */
class OctaneRequestReceivedStub {}

if (! class_exists('Laravel\\Octane\\Events\\RequestReceived')) {
    class_alias(OctaneRequestReceivedStub::class, 'Laravel\\Octane\\Events\\RequestReceived');
}

/**
 * Under Octane and in queue workers the process outlives the request: the
 * singletons' request memos and spatie's team id must not reach the next
 * request or job. A `sync` job runs INSIDE the request, so it keeps the team.
 */
class StateResetTest extends TestCase
{
    use CountsKinetixQueries;
    use ResolvesTeamRoutes;

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
        $app['config']->set('kinetix.teams', true);
        $app['config']->set('kinetix.settings.enabled', true);
        $app['config']->set('kinetix.help.enabled', true);
        $app['config']->set('kinetix.announcements.enabled', true);
        $app['config']->set('auth.providers.users.model', MemoTeamUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        (require __DIR__.'/../../database/migrations/2026_01_01_000002_create_kinetix_settings_table.php')->up();
    }

    private function memo(object $instance, string $property): array
    {
        return (array) (new ReflectionProperty($instance, $property))->getValue($instance);
    }

    private function seedMemos(): void
    {
        Setting::query()->create(['team_id' => null, 'key' => 'site.name', 'value' => json_encode('Before')]);
        $this->assertSame('Before', KinetixSettings::get('site.name'));

        $announcements = app(AnnouncementManager::class);
        (new ReflectionProperty($announcements, 'seenCache'))->setValue($announcements, ['user' => ['team' => null, 'global' => null]]);

        $help = app(HelpManager::class);
        (new ReflectionProperty($help, 'indexes'))->setValue($help, ['en@x' => ['articles' => [], 'bodies' => []]]);

        $this->assertNotSame([], $this->memo(app(SettingsManager::class), 'memo'));
    }

    private function assertMemosAreEmpty(): void
    {
        $this->assertSame([], $this->memo(app(SettingsManager::class), 'memo'));
        $this->assertSame([], $this->memo(app(AnnouncementManager::class), 'seenCache'));
        $this->assertSame([], $this->memo(app(HelpManager::class), 'indexes'));
    }

    public function test_the_reset_empties_the_request_memos_and_the_spatie_team(): void
    {
        $this->seedMemos();
        app(PermissionRegistrar::class)->setPermissionsTeamId(5);

        KinetixStateReset::run(true);

        $this->assertMemosAreEmpty();
        $this->assertNull(app(PermissionRegistrar::class)->getPermissionsTeamId());
    }

    public function test_the_request_team_is_resolved_again_after_a_reset(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->unsignedInteger('current_team_id')->nullable();
        });
        Schema::create('teams', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('slug')->unique();
        });
        Schema::create('team_user', function (Blueprint $table): void {
            $table->unsignedInteger('team_id');
            $table->unsignedInteger('user_id');
        });

        $team = MemoTeam::create(['slug' => 'alpha']);
        $user = MemoTeamUser::create(['name' => 'Alice']);
        DB::table('team_user')->insert(['team_id' => $team->getKey(), 'user_id' => $user->getKey()]);

        $this->actingAs($user);
        $this->withTeamSegment('alpha');
        KinetixTeams::currentTeamKey();

        $queries = $this->kinetixQueriesDuring(function (): void {
            KinetixTeams::currentTeamKey();
            KinetixStateReset::run(false);
            KinetixTeams::currentTeamKey();
        });

        $this->assertCount(1, $queries, $this->describeQueries($queries));
    }

    public function test_a_queued_job_on_a_worker_resets_the_spatie_team_and_the_memos(): void
    {
        $this->seedMemos();
        app(PermissionRegistrar::class)->setPermissionsTeamId(5);

        Event::dispatch(new JobProcessing('redis', $this->createMock(Job::class)));

        $this->assertMemosAreEmpty();
        $this->assertNull(app(PermissionRegistrar::class)->getPermissionsTeamId());
    }

    public function test_a_sync_job_keeps_the_requests_spatie_team_but_still_flushes_the_memos(): void
    {
        $this->seedMemos();
        app(PermissionRegistrar::class)->setPermissionsTeamId(5);

        Event::dispatch(new JobProcessing('sync', $this->createMock(Job::class)));

        $this->assertMemosAreEmpty();
        $this->assertSame(5, app(PermissionRegistrar::class)->getPermissionsTeamId());
    }

    public function test_a_sync_driver_connection_under_another_name_keeps_the_team(): void
    {
        config(['queue.connections.inline' => ['driver' => 'sync']]);
        $this->seedMemos();
        app(PermissionRegistrar::class)->setPermissionsTeamId(5);

        // Judged by the driver, not the name the app gave the connection.
        Event::dispatch(new JobProcessing('inline', $this->createMock(Job::class)));

        $this->assertMemosAreEmpty();
        $this->assertSame(5, app(PermissionRegistrar::class)->getPermissionsTeamId());

        // A real job pushed through it runs mid-request and keeps it too.
        Queue::connection('inline')->push(new StateResetNoopJob);

        $this->assertSame(5, app(PermissionRegistrar::class)->getPermissionsTeamId());
    }

    public function test_an_octane_request_resets_the_spatie_team_and_the_memos(): void
    {
        $this->seedMemos();
        app(PermissionRegistrar::class)->setPermissionsTeamId(5);

        Event::dispatch('Laravel\\Octane\\Events\\RequestReceived');

        $this->assertMemosAreEmpty();
        $this->assertNull(app(PermissionRegistrar::class)->getPermissionsTeamId());
    }

    public function test_a_setting_another_process_changed_is_read_after_the_reset(): void
    {
        $this->seedMemos();

        // Another process saves the setting: the row changes and the cache
        // entry is dropped, but this process still holds its memo.
        Setting::query()->where('key', 'site.name')->update(['value' => json_encode('After')]);
        cache()->flush();
        $this->assertSame('Before', KinetixSettings::get('site.name'));

        Event::dispatch(new JobProcessing('redis', $this->createMock(Job::class)));

        $this->assertSame('After', KinetixSettings::get('site.name'));
    }
}
