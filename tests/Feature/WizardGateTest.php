<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Happones\Kinetix\Wizards\WizardManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class WizardUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class WizardGateTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.wizards.enabled', true);
        $app['config']->set('kinetix.wizards.gates', [
            'account-setup' => 'wizard.setup',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        Schema::create('kinetix_wizard_completions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('slug')->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'team_id', 'slug']);
        });

        Route::middleware('web')->group(function () {
            Route::get('/setup', fn () => 'setup page')->name('wizard.setup');
            Route::get('/dashboard', fn () => 'dashboard')
                ->name('dashboard')
                ->middleware('kinetix.wizard:account-setup');
            Route::get('/open', fn () => 'open')
                ->middleware('kinetix.wizard:not-configured');
        });
    }

    private function user(): WizardUser
    {
        return WizardUser::create(['name' => 'New']);
    }

    public function test_incomplete_user_is_redirected_to_the_wizard_route(): void
    {
        $response = $this->actingAs($this->user())->get('/dashboard');

        $response->assertRedirect('/setup');
    }

    public function test_user_passes_once_the_wizard_is_completed(): void
    {
        $user = $this->user();

        app(WizardManager::class)->complete($user, 'account-setup');

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('dashboard');
    }

    public function test_wizard_route_itself_is_reachable_while_incomplete(): void
    {
        // No redirect loop: the gate lets the target route through.
        $this->actingAs($this->user())->get('/setup')->assertOk()->assertSee('setup page');
    }

    public function test_unconfigured_slug_does_not_block(): void
    {
        $this->actingAs($this->user())->get('/open')->assertOk()->assertSee('open');
    }

    public function test_complete_endpoint_marks_completion(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson('/_kinetix/wizards/account-setup/complete')
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertTrue(app(WizardManager::class)->hasCompleted($user, 'account-setup'));
        $this->assertDatabaseHas('kinetix_wizard_completions', [
            'user_id' => $user->getKey(),
            'slug'    => 'account-setup',
        ]);
    }

    public function test_status_endpoint_reports_completion(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->getJson('/_kinetix/wizards/account-setup')
            ->assertJsonPath('completed', false);

        app(WizardManager::class)->complete($user, 'account-setup');

        $this->actingAs($user)
            ->getJson('/_kinetix/wizards/account-setup')
            ->assertJsonPath('completed', true);
    }

    public function test_reset_clears_completion(): void
    {
        $user    = $this->user();
        $manager = app(WizardManager::class);

        $manager->complete($user, 'account-setup');
        $manager->reset($user, 'account-setup');

        $this->assertFalse($manager->hasCompleted($user, 'account-setup'));
    }

    public function test_completion_is_isolated_per_user(): void
    {
        $a = $this->user();
        $b = $this->user();

        app(WizardManager::class)->complete($a, 'account-setup');

        $this->assertFalse(app(WizardManager::class)->hasCompleted($b, 'account-setup'));
    }

    public static bool $businessReady = false;

    /**
     * A data-backed gate: is the team's business set up?
     */
    public static function businessReady(mixed $user, int|string|null $team): bool
    {
        return self::$businessReady;
    }

    private function inTeam(WizardUser $user, int $team): WizardUser
    {
        return $user->setRelation('currentTeam', (new WizardUser)->forceFill(['id' => $team]));
    }

    public function test_a_team_gate_opens_for_every_member_once_one_completes_it(): void
    {
        config()->set('kinetix.teams', true);
        config()->set('kinetix.wizards.gates.team-setup', ['route' => 'wizard.setup', 'scope' => 'team']);

        $owner    = $this->inTeam($this->user(), 7);
        $member   = $this->inTeam($this->user(), 7);
        $stranger = $this->inTeam($this->user(), 8);
        $wizards  = app(WizardManager::class);

        $wizards->complete($owner, 'team-setup');

        $this->assertTrue($wizards->hasCompleted($member, 'team-setup'));
        $this->assertFalse($wizards->hasCompleted($stranger, 'team-setup'));

        $wizards->reset($member, 'team-setup');

        $this->assertFalse($wizards->hasCompleted($owner, 'team-setup'));
    }

    public function test_a_gate_your_data_decides_cannot_be_opened_by_the_endpoint(): void
    {
        config()->set('kinetix.wizards.gates.business', ['route' => 'wizard.setup', 'completed' => [self::class, 'businessReady']]);
        self::$businessReady = false;
        $user                = $this->user();

        $this->actingAs($user)->postJson('/_kinetix/wizards/business/complete')->assertForbidden();
        $this->assertFalse(app(WizardManager::class)->hasCompleted($user, 'business'));

        self::$businessReady = true;

        $this->assertTrue(app(WizardManager::class)->hasCompleted($user, 'business'));
    }

    public function test_a_json_request_learns_where_the_wizard_is_instead_of_being_redirected(): void
    {
        $this->actingAs($this->user())
            ->getJson('/dashboard')
            ->assertStatus(409)
            ->assertJson(['wizard' => 'account-setup', 'url' => route('wizard.setup')]);
    }

    public function test_the_page_the_user_was_going_to_is_remembered_for_after_the_wizard(): void
    {
        $this->actingAs($this->user())
            ->get('/dashboard?tab=sales')
            ->assertRedirect(route('wizard.setup'))
            ->assertSessionHas('url.intended', url('/dashboard?tab=sales'));
    }
}
