<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Activity\Activity;
use Happones\Kinetix\Activity\ActivityLogged;
use Happones\Kinetix\Activity\Concerns\LogsKinetixActivity;
use Happones\Kinetix\Activity\KinetixActivity;
use Happones\Kinetix\Permissions\KinetixPermissions;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Permission\Traits\HasRoles;

class LoggedProduct extends Model
{
    use LogsKinetixActivity;

    protected $table = 'products';

    protected $guarded = [];
}

class ActivityUser extends Authenticatable
{
    use HasRoles;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected $guard_name = 'web';
}

class ActivityTest extends TestCase
{
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
        $app['config']->set('kinetix.activity.enabled', true);
        // spatie/activitylog is installed (dev dep), which would flip the `auto`
        // driver to spatie; pin this suite to the native store it asserts against.
        $app['config']->set('kinetix.activity.driver', 'native');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        foreach (KinetixPermissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }

    private function createTables(): void
    {
        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        Schema::create('products', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->integer('price')->nullable();
            $table->timestamps();
        });

        foreach (['permissions', 'roles'] as $name) {
            Schema::create($name, function ($table) {
                $table->bigIncrements('id');
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
        }

        Schema::create('role_has_permissions', function ($table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('model_has_permissions', function ($table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['permission_id', 'model_id', 'model_type']);
        });

        Schema::create('model_has_roles', function ($table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['role_id', 'model_id', 'model_type']);
        });

        Schema::create('kinetix_activity', function ($table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('log_name')->nullable();
            $table->string('event')->nullable();
            $table->text('description')->nullable();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('causer_type')->nullable();
            $table->unsignedBigInteger('causer_id')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
        });
    }

    private function manager(): ActivityUser
    {
        $user = ActivityUser::create(['name' => 'Manager']);
        $user->givePermissionTo('activity.view');

        return $user;
    }

    public function test_model_trait_logs_create_update_delete_with_causer_and_diff(): void
    {
        Event::fake([ActivityLogged::class]);

        $user = ActivityUser::create(['name' => 'Editor']);
        $this->actingAs($user);

        $product = LoggedProduct::create(['name' => 'Widget', 'price' => 10]);
        $product->update(['name' => 'Widget Pro']);

        $created = Activity::where('event', 'created')->firstOrFail();
        $this->assertSame(LoggedProduct::class, $created->subject_type);
        $this->assertSame($product->id, (int) $created->subject_id);
        $this->assertSame($user->id, (int) $created->causer_id);

        $updated = Activity::where('event', 'updated')->firstOrFail();
        $this->assertSame('Widget', $updated->properties['old']['name']);
        $this->assertSame('Widget Pro', $updated->properties['attributes']['name']);

        $product->delete();
        $this->assertTrue(Activity::where('event', 'deleted')->exists());

        Event::assertDispatched(ActivityLogged::class);
    }

    public function test_for_returns_paginated_entries_for_one_subject(): void
    {
        $this->actingAs(ActivityUser::create(['name' => 'Editor']));
        $product = LoggedProduct::create(['name' => 'A']);

        foreach (range(1, 20) as $i) {
            KinetixActivity::log('viewed', $product);
        }

        $result = KinetixActivity::for($product);

        $this->assertCount(15, $result['data']);          // default per_page
        $this->assertSame(21, $result['pagination']['total']); // 20 viewed + 1 created
        $this->assertGreaterThan(1, $result['pagination']['last_page']);
    }

    public function test_index_endpoint_filters_by_subject_and_is_gated(): void
    {
        $this->actingAs(ActivityUser::create(['name' => 'Editor']));
        $product = LoggedProduct::create(['name' => 'A']);

        $manager = $this->manager();

        $this->actingAs($manager)
            ->getJson('/_kinetix/activity?subject_type='.urlencode(LoggedProduct::class).'&subject_id='.$product->id)
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.event', 'created')
            ->assertJsonPath('data.0.causerName', 'Editor');

        $this->actingAs(ActivityUser::create(['name' => 'Nobody']))
            ->getJson('/_kinetix/activity')
            ->assertForbidden();
    }

    public function test_prune_deletes_old_entries(): void
    {
        $this->actingAs(ActivityUser::create(['name' => 'Editor']));
        $product = LoggedProduct::create(['name' => 'A']);

        $old = KinetixActivity::log('viewed', $product);
        $old->forceFill(['created_at' => now()->subDays(400)])->save();

        $this->artisan('kinetix:activity:prune')->assertSuccessful();

        $this->assertDatabaseMissing('kinetix_activity', ['id' => $old->id]);
        // The recent "created" entry survives.
        $this->assertTrue(Activity::where('event', 'created')->exists());
    }
}
