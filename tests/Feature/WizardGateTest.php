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
}
