<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Actions\EditAction;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class RouteBindingUser extends Authenticatable {}

/**
 * A model whose route key is a slug, not the primary key.
 */
class SlugPost extends Model
{
    protected $table = 'slug_posts';

    public $timestamps = false;

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}

class ActionRouteBindingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('slug_posts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('slug');
        });

        Route::get('posts/{post}/edit', fn () => '')->name('posts.edit');

        Gate::before(fn () => true);
        $this->actingAs(new RouteBindingUser);
    }

    public function test_action_url_uses_the_route_key_not_the_primary_key(): void
    {
        // id = 1, but the route key is the slug.
        $post = SlugPost::create(['slug' => 'hello-world']);

        $action = Action::make('edit')->url(fn (SlugPost $record) => route('posts.edit', $record));
        $data   = $action->toData($post);

        $this->assertNotNull($data);
        $this->assertStringContainsString('posts/hello-world/edit', (string) $data->url);
        $this->assertStringNotContainsString('posts/1/edit', (string) $data->url);
    }

    public function test_inertia_visit_accepts_a_per_record_url_closure(): void
    {
        $post = SlugPost::create(['slug' => 'hello-world']);

        $data = Action::make('delete')
            ->inertiaVisit(fn (SlugPost $record) => route('posts.edit', $record), ['method' => 'delete'])
            ->toData($post);

        $this->assertNotNull($data);
        $this->assertStringContainsString('posts/hello-world/edit', (string) $data->url);
        $this->assertSame('delete', $data->inertiaVisit['method'] ?? null);
    }

    public function test_prebuilt_edit_action_respects_custom_route_binding(): void
    {
        $post = SlugPost::create(['slug' => 'my-first-post']);

        // The instance is handed to the url() closure; route() serializes it via
        // getRouteKey() (= getRouteKeyName), so a slug binding resolves correctly.
        $data = EditAction::make()
            ->url(fn (SlugPost $record) => route('posts.edit', $record))
            ->toData($post);

        $this->assertNotNull($data);
        $this->assertStringContainsString('my-first-post', (string) $data->url);
    }
}
