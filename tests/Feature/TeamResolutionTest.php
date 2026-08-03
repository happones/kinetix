<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Settings\SettingsManager;
use Happones\Kinetix\Support\KinetixTeams;
use Happones\Kinetix\Tests\Concerns\ResolvesTeamRoutes;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ResolvedTeam extends Model
{
    protected $table = 'teams';

    public $timestamps = false;

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}

class ResolvedTeamUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return BelongsToMany<ResolvedTeam, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(ResolvedTeam::class, 'team_user', 'user_id', 'team_id');
    }

    /**
     * @return BelongsTo<ResolvedTeam, $this>
     */
    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(ResolvedTeam::class, 'current_team_id');
    }
}

/**
 * Modules used to resolve the tenant from `$user->currentTeam`, which ignores
 * the `{current_team}` segment: a page served for team B read and wrote team A's
 * rows. Everything now goes through `KinetixTeams`.
 */
class TeamResolutionTest extends TestCase
{
    use ResolvesTeamRoutes;

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('kinetix.teams', true);
        $app['config']->set('kinetix.settings.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->unsignedInteger('current_team_id')->nullable();
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->increments('id');
            $table->string('slug');
        });

        Schema::create('team_user', function (Blueprint $table) {
            $table->unsignedInteger('team_id');
            $table->unsignedInteger('user_id');
        });

        (require __DIR__.'/../../database/migrations/2026_01_01_000002_create_kinetix_settings_table.php')->up();

        ResolvedTeam::create(['id' => 1, 'slug' => 'alpha']);
        ResolvedTeam::create(['id' => 2, 'slug' => 'beta']);
    }

    public function test_the_url_segment_wins_over_the_users_current_team(): void
    {
        $this->actingAs($this->member(currentTeamId: 1));
        $this->withTeamSegment('beta');

        // The regression: this used to return 1 (the user's currentTeam).
        $this->assertSame(2, (int) KinetixTeams::keyFor('settings'));
    }

    public function test_it_falls_back_to_the_current_team_without_a_segment(): void
    {
        $this->actingAs($this->member(currentTeamId: 1));
        $this->withoutTeamSegment();

        $this->assertSame(1, (int) KinetixTeams::keyFor('settings'));
    }

    public function test_the_billing_team_param_is_accepted_too(): void
    {
        $this->actingAs($this->member(currentTeamId: 1));
        $this->withTeamSegment('beta', param: 'team');

        // Billing mounts its routes under `{team}`; a Kinetix component rendered
        // inside that page must not fall back to the user's currentTeam.
        $this->assertSame(2, (int) KinetixTeams::keyFor('settings'));
    }

    public function test_a_bound_team_model_resolves_to_its_key(): void
    {
        $this->actingAs($this->member(currentTeamId: 1));
        $this->withTeamSegment(ResolvedTeam::find(2));

        $this->assertSame(2, (int) KinetixTeams::keyFor('settings'));
    }

    public function test_a_team_the_user_does_not_belong_to_is_refused(): void
    {
        $user = $this->member(currentTeamId: 1);
        $user->teams()->detach(2);
        $this->actingAs($user);
        $this->withTeamSegment('beta');

        $this->expectException(NotFoundHttpException::class);

        KinetixTeams::keyFor('settings');
    }

    public function test_a_module_with_teams_off_resolves_to_null(): void
    {
        config()->set('kinetix.settings.teams', false);

        $this->actingAs($this->member(currentTeamId: 1));
        $this->withTeamSegment('beta');

        $this->assertNull(KinetixTeams::keyFor('settings'));
    }

    public function test_settings_are_written_to_the_team_in_the_url(): void
    {
        $this->actingAs($this->member(currentTeamId: 1));
        $this->withTeamSegment('beta');

        app(SettingsManager::class)->set('currency', 'MXN');

        $this->assertDatabaseHas('kinetix_settings', ['team_id' => 2, 'key' => 'currency']);
        $this->assertDatabaseMissing('kinetix_settings', ['team_id' => 1, 'key' => 'currency']);
    }

    public function test_settings_written_for_one_team_are_invisible_to_another(): void
    {
        $user = $this->member(currentTeamId: 1);
        $this->actingAs($user);

        $this->withTeamSegment('beta');
        app(SettingsManager::class)->set('currency', 'MXN');

        $this->withTeamSegment('alpha');
        $this->assertNull(app(SettingsManager::class)->get('currency'));
    }

    private function member(int $currentTeamId): ResolvedTeamUser
    {
        $user = ResolvedTeamUser::query()->firstOrCreate(
            ['id' => 1],
            ['name' => 'Member', 'current_team_id' => $currentTeamId],
        );

        $user->teams()->syncWithoutDetaching([1, 2]);

        return $user;
    }
}
