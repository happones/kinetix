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
}
