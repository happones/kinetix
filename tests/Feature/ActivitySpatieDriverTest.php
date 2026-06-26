<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Activity\Concerns\LogsKinetixActivity;
use Happones\Kinetix\Activity\KinetixActivity;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

class SpatieProduct extends Model
{
    use LogsKinetixActivity;

    protected $table = 'products';

    protected $guarded = [];
}

class SpatieActivityUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class ActivitySpatieDriverTest extends TestCase
{
    /**
     * @param  Application              $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), ActivitylogServiceProvider::class];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('kinetix.activity.enabled', true);
        $app['config']->set('kinetix.activity.driver', 'spatie');
        $app['config']->set('kinetix.activity.teams', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        Schema::create('products', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->timestamps();
        });

        // spatie/laravel-activitylog's table.
        Schema::create('activity_log', function ($table) {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
        });
    }

    public function test_trait_logs_through_spatie_with_diff_and_causer(): void
    {
        $user = SpatieActivityUser::create(['name' => 'Editor']);
        $this->actingAs($user);

        $product = SpatieProduct::create(['name' => 'Widget']);
        $product->update(['name' => 'Widget Pro']);

        // Stored in spatie's table, not the native one.
        $this->assertSame(2, SpatieActivity::count());

        $updated = SpatieActivity::where('event', 'updated')->firstOrFail();
        $this->assertSame('Widget', $updated->properties['old']['name']);
        $this->assertSame('Widget Pro', $updated->properties['attributes']['name']);
        $this->assertSame($user->id, (int) $updated->causer_id);
    }

    public function test_query_normalizes_spatie_entries_to_the_kinetix_dto(): void
    {
        $this->actingAs(SpatieActivityUser::create(['name' => 'Editor']));
        $product = SpatieProduct::create(['name' => 'A']);

        $result = KinetixActivity::for($product);

        $this->assertSame(1, $result['pagination']['total']);
        $this->assertSame('created', $result['data'][0]->event);
        $this->assertSame('Editor', $result['data'][0]->causerName);
        $this->assertSame(SpatieProduct::class, $result['data'][0]->subjectType);
    }

    public function test_team_scoping_lives_in_properties_without_touching_spatie_schema(): void
    {
        config()->set('kinetix.activity.teams', true);

        // No current team → team-less entry; still readable in the global scope.
        $this->actingAs(SpatieActivityUser::create(['name' => 'Editor']));
        $product = SpatieProduct::create(['name' => 'A']);

        $this->assertNull(SpatieActivity::first()->properties['team_id'] ?? null);
        $this->assertSame(1, KinetixActivity::for($product)['pagination']['total']);
    }
}
