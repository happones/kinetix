<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Happones\Kinetix\Tours\KinetixTours;
use Happones\Kinetix\Tours\TourRegistry;
use Happones\Kinetix\Tours\TourState;
use Happones\Kinetix\Tours\TourStep;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class TourUser extends Authenticatable
{
    protected $table = 'tour_users';

    public $timestamps = false;

    protected $guarded = [];
}

class ToursTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.tours.enabled', true);
        $app['config']->set('kinetix.tours.driver', 'database');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tour_users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        $migration = require __DIR__.'/../../database/migrations/2026_01_01_000023_create_kinetix_tour_state_table.php';
        $migration->up();
    }

    private function declareTour(): void
    {
        KinetixTours::tour('posts')
            ->page('Kinetix/Posts/Index')
            ->steps([
                TourStep::make('[data-tour=create]')
                    ->title('Create records')
                    ->description('Start here.')
                    ->side('bottom'),
                TourStep::make('[data-tour=filters]')->title('Filter & search'),
            ]);
    }

    /**
     * @return array{enabled: bool, driver: string, tours: array<int, array<string, mixed>>, seen: array<int, string>}
     */
    private function sharedTours(): array
    {
        /** @var callable $shared */
        $shared = Inertia::getShared('kinetix_tours');

        return value($shared);
    }

    public function test_declared_tours_are_shared_with_their_steps(): void
    {
        $this->declareTour();
        $this->actingAs(TourUser::create(['name' => 'Jane']));

        $data = $this->sharedTours();

        $this->assertTrue($data['enabled']);
        $this->assertSame('database', $data['driver']);
        $this->assertSame('posts', $data['tours'][0]['id']);
        $this->assertSame('Kinetix/Posts/Index', $data['tours'][0]['page']);
        $this->assertTrue($data['tours'][0]['auto']);
        $this->assertSame('[data-tour=create]', $data['tours'][0]['steps'][0]['selector']);
        $this->assertSame('bottom', $data['tours'][0]['steps'][0]['side']);
    }

    public function test_permission_gated_tours_are_omitted_from_the_share(): void
    {
        Gate::define('billing.manage', fn ($user): bool => $user->name === 'Owner');

        $this->declareTour();
        KinetixTours::tour('billing')
            ->url('/billing*')
            ->permission('billing.manage')
            ->steps([TourStep::make('[data-tour=plans]')->title('Plans')]);

        $this->actingAs(TourUser::create(['name' => 'Jane']));
        $ids = array_column($this->sharedTours()['tours'], 'id');
        $this->assertSame(['posts'], $ids);

        $this->actingAs(TourUser::create(['name' => 'Owner']));
        $ids = array_column($this->sharedTours()['tours'], 'id');
        $this->assertSame(['posts', 'billing'], $ids);
    }

    public function test_share_is_disabled_for_guests(): void
    {
        $this->declareTour();

        $data = $this->sharedTours();

        $this->assertFalse($data['enabled']);
        $this->assertSame([], $data['tours']);
    }

    public function test_seen_endpoint_persists_per_user_and_is_idempotent(): void
    {
        $this->declareTour();
        $user = TourUser::create(['name' => 'Jane']);

        $this->actingAs($user)
            ->postJson('/_kinetix/tours/posts/seen')
            ->assertOk();
        $this->actingAs($user)
            ->postJson('/_kinetix/tours/posts/seen')
            ->assertOk();

        $this->assertSame(1, TourState::query()->count());
        $this->assertSame(['posts'], $this->sharedTours()['seen']);

        // Another user's share is unaffected.
        $this->actingAs(TourUser::create(['name' => 'Other']));
        $this->assertSame([], $this->sharedTours()['seen']);
    }

    public function test_reset_endpoint_rearms_the_tour(): void
    {
        $this->declareTour();
        $user = TourUser::create(['name' => 'Jane']);

        $this->actingAs($user)->postJson('/_kinetix/tours/posts/seen')->assertOk();
        $this->actingAs($user)->deleteJson('/_kinetix/tours/posts/seen')->assertOk();

        $this->assertSame(0, TourState::query()->count());
    }

    public function test_unknown_tour_ids_are_rejected(): void
    {
        $this->declareTour();

        $this->actingAs(TourUser::create(['name' => 'Jane']))
            ->postJson('/_kinetix/tours/nope/seen')
            ->assertNotFound();

        $this->assertSame(0, TourState::query()->count());
    }

    public function test_registry_accumulates_and_is_fluent(): void
    {
        $registry = app(TourRegistry::class);

        KinetixTours::tour('a')->step(TourStep::make('#x')->title('X'));
        KinetixTours::tour('a')->auto(false);

        $this->assertCount(1, $registry->tours());
        $this->assertFalse($registry->tours()['a']->toArray()['auto']);
    }

    public function test_step_text_from_translation_keys_follows_the_request_locale(): void
    {
        app('translator')->addLines(['tours.create' => 'Create records'], 'en');
        app('translator')->addLines(['tours.create' => 'Crear registros'], 'es');
        app('translator')->addLines(['tours.create_body' => 'Start with :what.'], 'en');
        app('translator')->addLines(['tours.create_body' => 'Empieza con :what.'], 'es');

        // Declared at boot, in the app's locale…
        app()->setLocale('en');
        KinetixTours::tour('posts')->steps([
            TourStep::make('[data-action=create]')
                ->titleKey('tours.create')
                ->descriptionKey('tours.create_body', ['what' => 'Ada']),
        ]);

        // …and shown to a Spanish viewer after the locale middleware ran.
        $this->actingAs(TourUser::create(['name' => 'Jane']));
        app()->setLocale('es');

        $step = $this->sharedTours()['tours'][0]['steps'][0];

        $this->assertSame('Crear registros', $step['title']);
        $this->assertSame('Empieza con Ada.', $step['description']);
    }

    public function test_a_plain_title_after_a_key_wins_and_vice_versa(): void
    {
        $step = TourStep::make('#x')->titleKey('tours.create')->title('Plain');
        $this->assertSame('Plain', $step->toArray()['title']);

        $step = TourStep::make('#x')->title('Plain')->titleKey('tours.missing');
        $this->assertSame('tours.missing', $step->toArray()['title']);
    }

    public function test_a_bumped_version_stores_seen_under_its_own_key(): void
    {
        KinetixTours::tour('posts')->page('Kinetix/Posts/Index')->steps([TourStep::make('#a')]);
        $user = TourUser::create(['name' => 'Jane']);

        $this->actingAs($user)->postJson('/_kinetix/tours/posts/seen')->assertOk();
        $this->assertSame(['posts'], $this->sharedTours()['seen']);

        // Content changed: same id, version 2 — the v1 "seen" no longer counts.
        KinetixTours::tour('posts')->version(2);
        $tour = $this->sharedTours()['tours'][0];

        $this->assertSame('posts', $tour['id']);
        $this->assertSame(2, $tour['version']);
        $this->assertSame('posts@v2', $tour['seenKey']);
        $this->assertNotContains($tour['seenKey'], $this->sharedTours()['seen']);

        $this->actingAs($user)->postJson('/_kinetix/tours/posts/seen')->assertOk();
        $this->assertContains('posts@v2', $this->sharedTours()['seen']);

        $this->actingAs($user)->deleteJson('/_kinetix/tours/posts/seen')->assertOk();
        $this->assertSame(['posts'], $this->sharedTours()['seen']);
    }

    public function test_tours_skip_automated_browsers_unless_switched_off(): void
    {
        $this->actingAs(TourUser::create(['name' => 'Jane']));
        $this->assertTrue($this->sharedTours()['skipWhenAutomated']);

        config()->set('kinetix.tours.skip_when_automated', false);
        $this->assertFalse($this->sharedTours()['skipWhenAutomated']);
    }

    public function test_step_helpers_target_the_hooks_kinetix_renders(): void
    {
        $this->assertSame('[data-action="create"]', TourStep::action('create')->selector);
        $this->assertSame('[data-kinetix-table-header]', TourStep::tableHeader()->selector);
        $this->assertSame('[data-kinetix-wizard-step="billing"]', TourStep::wizardStep('billing')->selector);

        $field = TourStep::field('department_id')->toArray();
        $this->assertSame('[data-kinetix-field="department_id"]', $field['selector']);
        $this->assertSame(['field' => 'department_id'], $field['reveal']);
        $this->assertFalse($field['optional']);

        $tab = TourStep::tab('employment')->optional()->toArray();
        $this->assertSame('[data-kinetix-tab="employment"]', $tab['selector']);
        $this->assertSame(['tab' => 'employment'], $tab['reveal']);
        $this->assertTrue($tab['optional']);

        // A plain selector reveals nothing.
        $this->assertNull(TourStep::make('#x')->toArray()['reveal']);
    }

    public function test_step_helper_values_cannot_break_out_of_the_selector(): void
    {
        $this->assertSame('[data-action="a\\"b"]', TourStep::action('a"b')->selector);
    }
}
