<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Dismissals\KinetixDismissals;
use Happones\Kinetix\KinetixServiceProvider;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;

class DismissalUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * The Dismissals module: the server-side memory that lets a `permanent`
 * `<KinetixAlert>` close follow the user to every device.
 */
class DismissalsTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.dismissals.enabled', true);
        $app['config']->set('auth.providers.users.model', DismissalUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        $this->migration()->up();
    }

    private function migration(): object
    {
        return require __DIR__.'/../../database/migrations/2026_01_01_000036_create_kinetix_dismissals_table.php';
    }

    private function user(): DismissalUser
    {
        return DismissalUser::query()->create(['name' => 'Ana']);
    }

    /**
     * @return list<string>|null
     */
    private function sharedKeys(): ?array
    {
        $shared = Inertia::getShared('kinetix_dismissals');

        return is_callable($shared) ? $shared() : $shared;
    }

    public function test_a_close_is_remembered_until_restored(): void
    {
        $user = $this->user();

        $this->assertFalse(KinetixDismissals::has($user, 'complete-profile'));

        KinetixDismissals::dismiss($user, 'complete-profile');
        $this->assertTrue(KinetixDismissals::has($user, 'complete-profile'));

        KinetixDismissals::restore($user, 'complete-profile');
        $this->assertFalse(KinetixDismissals::has($user, 'complete-profile'));
    }

    public function test_a_timed_close_lapses_on_its_own(): void
    {
        $user = $this->user();

        KinetixDismissals::dismiss($user, 'survey', now()->addDay());
        $this->assertTrue(KinetixDismissals::has($user, 'survey'));

        $this->travel(2)->days();

        $this->assertFalse(KinetixDismissals::has($user, 'survey'));
        $this->assertSame([], KinetixDismissals::keysFor($user));
    }

    public function test_closes_belong_to_their_user(): void
    {
        $ana = $this->user();
        $bob = DismissalUser::query()->create(['name' => 'Bob']);

        KinetixDismissals::dismiss($ana, 'beta');

        $this->assertFalse(KinetixDismissals::has($bob, 'beta'));
        $this->assertSame([], KinetixDismissals::keysFor($bob));
    }

    public function test_the_endpoint_stores_a_close_with_an_optional_lifetime(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson('/_kinetix/dismissals', ['key' => 'beta:2026', 'minutes' => 60])
            ->assertOk()
            ->assertJson(['dismissed' => true]);

        $this->assertTrue(KinetixDismissals::has($user, 'beta:2026'));

        $this->travel(61)->minutes();
        $this->assertFalse(KinetixDismissals::has($user, 'beta:2026'));
    }

    public function test_the_endpoint_rejects_a_key_that_is_not_a_key(): void
    {
        $this->actingAs($this->user())
            ->postJson('/_kinetix/dismissals', ['key' => '../etc passwd'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('key');
    }

    public function test_the_endpoint_takes_a_close_back(): void
    {
        $user = $this->user();
        KinetixDismissals::dismiss($user, 'promo');

        $this->actingAs($user)
            ->deleteJson('/_kinetix/dismissals/promo')
            ->assertOk();

        $this->assertFalse(KinetixDismissals::has($user, 'promo'));
    }

    public function test_the_keys_ride_the_page_payload(): void
    {
        $user = $this->user();
        KinetixDismissals::dismiss($user, 'promo');

        $this->actingAs($user);

        $this->assertSame(['promo'], $this->sharedKeys());
    }

    public function test_the_payload_is_null_for_guests_and_when_the_module_is_off(): void
    {
        $this->assertNull($this->sharedKeys());

        $this->actingAs($this->user());
        config()->set('kinetix.dismissals.enabled', false);

        $this->assertNull($this->sharedKeys());
    }

    public function test_the_migration_is_idempotent_and_published_on_its_own_tag(): void
    {
        $this->migration()->up();

        $this->assertTrue(Schema::hasTable('kinetix_dismissals'));

        $paths = ServiceProvider::pathsToPublish(KinetixServiceProvider::class, 'kinetix-dismissals-migrations');

        $this->assertSame(
            ['2026_01_01_000036_create_kinetix_dismissals_table.php'],
            array_map('basename', array_values($paths)),
        );
    }
}
