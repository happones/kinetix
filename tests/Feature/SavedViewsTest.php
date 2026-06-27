<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\SavedViews\SavedView;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;

class SavedViewUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class SavedViewsTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.saved_views.enabled', true);
        $app['config']->set('auth.providers.users.model', SavedViewUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });
        Schema::create('kinetix_saved_views', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('view_key');
            $table->string('name');
            $table->json('state');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    private function user(): SavedViewUser
    {
        return SavedViewUser::create(['name' => 'Ada']);
    }

    public function test_stores_and_lists_a_view(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson('/_kinetix/saved-views', [
                'key'   => 'App\\Models\\Post',
                'name'  => 'Active',
                'state' => ['search' => 'foo', 'filters' => ['status' => 'active']],
            ])
            ->assertCreated()
            ->assertJsonPath('views.0.name', 'Active')
            ->assertJsonPath('views.0.state.search', 'foo');

        $this->assertDatabaseHas('kinetix_saved_views', ['name' => 'Active', 'view_key' => 'App\\Models\\Post']);
    }

    public function test_index_is_scoped_to_the_view_key_and_user(): void
    {
        $user  = $this->user();
        $other = SavedViewUser::create(['name' => 'Bob']);
        SavedView::create(['user_id' => $user->id, 'view_key' => 'posts', 'name' => 'Mine', 'state' => []]);
        SavedView::create(['user_id' => $user->id, 'view_key' => 'orders', 'name' => 'Other key', 'state' => []]);
        SavedView::create(['user_id' => $other->id, 'view_key' => 'posts', 'name' => 'Theirs', 'state' => []]);

        $this->actingAs($user)
            ->getJson('/_kinetix/saved-views?key=posts')
            ->assertOk()
            ->assertJsonCount(1, 'views')
            ->assertJsonPath('views.0.name', 'Mine');
    }

    public function test_set_default_clears_other_defaults(): void
    {
        $user = $this->user();
        $a    = SavedView::create(['user_id' => $user->id, 'view_key' => 'posts', 'name' => 'A', 'state' => [], 'is_default' => true]);
        $b    = SavedView::create(['user_id' => $user->id, 'view_key' => 'posts', 'name' => 'B', 'state' => []]);

        $this->actingAs($user)
            ->postJson("/_kinetix/saved-views/{$b->id}/default")
            ->assertOk();

        $this->assertFalse($a->fresh()->is_default);
        $this->assertTrue($b->fresh()->is_default);
    }

    public function test_cannot_touch_another_users_view(): void
    {
        $owner    = $this->user();
        $view     = SavedView::create(['user_id' => $owner->id, 'view_key' => 'posts', 'name' => 'A', 'state' => []]);
        $intruder = SavedViewUser::create(['name' => 'Eve']);

        $this->actingAs($intruder)
            ->deleteJson("/_kinetix/saved-views/{$view->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('kinetix_saved_views', ['id' => $view->id]);
    }

    public function test_deletes_a_view(): void
    {
        $user = $this->user();
        $view = SavedView::create(['user_id' => $user->id, 'view_key' => 'posts', 'name' => 'A', 'state' => []]);

        $this->actingAs($user)
            ->deleteJson("/_kinetix/saved-views/{$view->id}")
            ->assertOk()
            ->assertJsonCount(0, 'views');
    }
}
