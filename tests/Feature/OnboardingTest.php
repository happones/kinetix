<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Onboarding\KinetixOnboarding;
use Happones\Kinetix\Onboarding\OnboardingManager;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class OnboardingUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    public bool $verified = false;

    public function hasVerifiedEmail(): bool
    {
        return $this->verified;
    }
}

class OnboardingTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.onboarding.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        Schema::create('kinetix_onboarding', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->json('completed')->nullable();
            $table->boolean('dismissed')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'team_id']);
        });

        KinetixOnboarding::step('manual-step', 'Do the thing')
            ->description('A manually-completed step.')
            ->cta('Open', '/somewhere');

        KinetixOnboarding::step('verify-email', 'Verify your email')
            ->completedUsing(fn (OnboardingUser $user): bool => $user->hasVerifiedEmail());
    }

    private function user(): OnboardingUser
    {
        return OnboardingUser::create(['name' => 'New User']);
    }

    public function test_index_returns_steps_with_computed_completion(): void
    {
        $user = $this->user();

        $response = $this->actingAs($user)->getJson('/_kinetix/onboarding');

        $response->assertOk();
        $response->assertJsonPath('total', 2);
        $response->assertJsonPath('completedCount', 0);
        $response->assertJsonPath('complete', false);
        $response->assertJsonPath('steps.0.key', 'manual-step');
        $response->assertJsonPath('steps.0.manual', true);
        $response->assertJsonPath('steps.1.manual', false);
    }

    public function test_a_closure_cta_href_is_resolved_per_request_with_the_user(): void
    {
        KinetixOnboarding::step('team-step', 'Set up your team')
            ->cta('Open settings', fn (OnboardingUser $user): string => "/teams/{$user->getKey()}/settings");

        $user = $this->user();

        $this->actingAs($user)
            ->getJson('/_kinetix/onboarding')
            ->assertOk()
            ->assertJsonPath('steps.2.ctaHref', "/teams/{$user->getKey()}/settings");
    }

    public function test_auto_detected_steps_complete_from_app_state(): void
    {
        $user           = $this->user();
        $user->verified = true;

        $response = $this->actingAs($user)->getJson('/_kinetix/onboarding');

        $response->assertJsonPath('steps.1.completed', true);
        $response->assertJsonPath('completedCount', 1);
    }

    public function test_completing_a_manual_step_persists_and_updates_progress(): void
    {
        $user = $this->user();

        $response = $this->actingAs($user)
            ->postJson('/_kinetix/onboarding/complete', ['step' => 'manual-step']);

        $response->assertOk();
        $response->assertJsonPath('steps.0.completed', true);
        $response->assertJsonPath('completedCount', 1);

        $this->assertDatabaseHas('kinetix_onboarding', ['user_id' => $user->getKey()]);
    }

    public function test_completing_an_unknown_step_is_a_no_op(): void
    {
        $user = $this->user();

        $response = $this->actingAs($user)
            ->postJson('/_kinetix/onboarding/complete', ['step' => 'nope']);

        $response->assertOk();
        $response->assertJsonPath('completedCount', 0);
    }

    public function test_full_completion_flag_is_set_when_all_steps_done(): void
    {
        $user           = $this->user();
        $user->verified = true; // auto-completes verify-email

        app(OnboardingManager::class)->complete($user, 'manual-step');

        $this->actingAs($user)
            ->getJson('/_kinetix/onboarding')
            ->assertJsonPath('complete', true);
    }

    public function test_dismiss_hides_the_checklist(): void
    {
        $user = $this->user();

        $this->actingAs($user)->postJson('/_kinetix/onboarding/dismiss')->assertOk();

        $this->actingAs($user)
            ->getJson('/_kinetix/onboarding')
            ->assertJsonPath('dismissed', true);
    }

    public function test_progress_is_isolated_per_user(): void
    {
        $a = $this->user();
        $b = $this->user();

        app(OnboardingManager::class)->complete($a, 'manual-step');

        $this->actingAs($b)
            ->getJson('/_kinetix/onboarding')
            ->assertJsonPath('completedCount', 0);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/_kinetix/onboarding')->assertStatus(401);
    }

    public function test_the_page_payload_carries_the_checklist(): void
    {
        $user = $this->user();
        app(OnboardingManager::class)->complete($user, 'manual-step');

        $this->actingAs($user);

        /** @var callable $shared */
        $shared = Inertia::getShared('kinetix_onboarding');
        $state  = value($shared)->toArray();

        // The checklist reads this instead of fetching on mount.
        $this->assertSame(1, $state['completedCount']);
        $this->assertSame(2, $state['total']);
        $this->assertFalse($state['dismissed']);
    }

    public function test_the_payload_is_absent_for_guests_and_when_sharing_is_off(): void
    {
        /** @var callable $shared */
        $shared = Inertia::getShared('kinetix_onboarding');

        // No user, nothing to say.
        $this->assertNull(value($shared));

        config()->set('kinetix.onboarding.share', false);
        $this->actingAs($this->user());

        $this->assertNull(value($shared));
    }

    public function test_reading_the_checklist_does_not_write_a_progress_row(): void
    {
        // The payload is built on EVERY response, so a read must stay a read —
        // otherwise the first page view of every account writes a row.
        $user = $this->user();

        app(OnboardingManager::class)->for($user);

        $this->assertDatabaseMissing('kinetix_onboarding', ['user_id' => $user->getKey()]);
    }
}
