<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Spotlight\KinetixSpotlight;
use Happones\Kinetix\Spotlight\SpotlightController;
use Happones\Kinetix\Spotlight\SpotlightLink;
use Happones\Kinetix\Spotlight\SpotlightResource;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

class SpotProduct extends Model
{
    protected $table = 'products';

    protected $guarded = [];

    protected $casts = ['visible' => 'boolean'];
}

class SpotUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class SpotProductPolicy
{
    public function view(SpotUser $user, SpotProduct $product): bool
    {
        return (bool) $product->visible;
    }
}

class SpotlightTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.spotlight.enabled', true);
        $app['config']->set('kinetix.spotlight.driver', 'database');
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
            $table->string('name');
            $table->boolean('visible')->default(true);
            $table->timestamps();
        });
    }

    private function search(string $q): array
    {
        $request = Request::create('/_kinetix/spotlight', 'GET', ['q' => $q]);

        return app(SpotlightController::class)->search($request)->getData(true);
    }

    public function test_database_search_and_empty_query_returns_only_links(): void
    {
        SpotProduct::create(['name' => 'Widget']);
        SpotProduct::create(['name' => 'Gadget']);

        KinetixSpotlight::register([
            SpotlightResource::make(SpotProduct::class)->searchColumns(['name'])->group('Products'),
            SpotlightLink::make('Billing')->url('/billing')->keywords(['invoice']),
        ]);

        // Query matches one product + the (always-listed) link doesn't match → product only.
        $groups = collect($this->search('wid')['groups'])->keyBy('label');
        $this->assertCount(1, $groups['Products']['items']);
        $this->assertSame('Widget', $groups['Products']['items'][0]['title']);

        // Empty query → no DB dump, only links.
        $groups = collect($this->search('')['groups'])->keyBy('label');
        $this->assertFalse($groups->has('Products'));
        $this->assertSame('Billing', $groups['Navigation']['items'][0]['title']);
    }

    public function test_per_record_policy_filters_results(): void
    {
        Gate::policy(SpotProduct::class, SpotProductPolicy::class);

        SpotProduct::create(['name' => 'Visible Widget', 'visible' => true]);
        SpotProduct::create(['name' => 'Hidden Widget', 'visible' => false]);

        KinetixSpotlight::register([
            SpotlightResource::make(SpotProduct::class)->searchColumns(['name'])->group('Products'),
        ]);

        $this->actingAs(SpotUser::create(['name' => 'User']));

        $items = collect($this->search('widget')['groups'])->keyBy('label')['Products']['items'];
        $this->assertCount(1, $items);
        $this->assertSame('Visible Widget', $items[0]['title']);
    }

    public function test_source_level_ability_hides_a_source(): void
    {
        Gate::define('see-secret', fn () => false);

        KinetixSpotlight::register([
            SpotlightLink::make('Secret')->url('/secret')->authorize('see-secret'),
            SpotlightLink::make('Public')->url('/public'),
        ]);

        $this->actingAs(SpotUser::create(['name' => 'User']));

        $titles = collect($this->search('')['groups'])
            ->flatMap(fn ($g) => $g['items'])
            ->pluck('title');

        $this->assertContains('Public', $titles->all());
        $this->assertNotContains('Secret', $titles->all());
    }

    public function test_endpoint_returns_grouped_results(): void
    {
        SpotProduct::create(['name' => 'Widget']);

        KinetixSpotlight::register([
            SpotlightResource::make(SpotProduct::class)->searchColumns(['name'])->group('Products'),
        ]);

        $this->actingAs(SpotUser::create(['name' => 'User']))
            ->getJson('/_kinetix/spotlight?q=wid')
            ->assertOk()
            ->assertJsonPath('groups.0.label', 'Products')
            ->assertJsonPath('groups.0.items.0.title', 'Widget');
    }
}
