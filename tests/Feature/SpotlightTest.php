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
        // The endpoint is throttled, and the rate limiter needs a store —
        // testbench defaults to the database driver with no `cache` table.
        $app['config']->set('cache.default', 'array');
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

    public function test_a_query_below_the_minimum_never_reaches_the_database(): void
    {
        SpotProduct::create(['name' => 'Widget']);

        KinetixSpotlight::register([
            SpotlightResource::make(SpotProduct::class)->searchColumns(['name'])->group('Products'),
            SpotlightLink::make('Widgets')->url('/widgets'),
        ]);

        // One character: the record source stays out of it…
        $groups = collect($this->search('w')['groups'])->keyBy('label');
        $this->assertFalse($groups->has('Products'));

        // …while the in-memory link source answers as it always has.
        $this->assertSame('Widgets', $groups['Navigation']['items'][0]['title']);

        // Two characters clears the floor.
        $groups = collect($this->search('wi')['groups'])->keyBy('label');
        $this->assertCount(1, $groups['Products']['items']);
    }

    public function test_the_minimum_query_length_is_configurable(): void
    {
        config()->set('kinetix.spotlight.min_chars', 3);

        SpotProduct::create(['name' => 'Widget']);

        KinetixSpotlight::register([
            SpotlightResource::make(SpotProduct::class)->searchColumns(['name'])->group('Products'),
        ]);

        $this->assertFalse(collect($this->search('wi')['groups'])->keyBy('label')->has('Products'));
        $this->assertTrue(collect($this->search('wid')['groups'])->keyBy('label')->has('Products'));
    }

    public function test_the_search_endpoint_is_throttled(): void
    {
        $route = collect(app('router')->getRoutes())
            ->first(fn ($r): bool => $r->getName() === 'kinetix.spotlight.search');

        $this->assertNotNull($route);
        $this->assertContains('throttle:60,1', $route->gatherMiddleware());
    }

    public function test_the_per_source_limit_defaults_to_the_config(): void
    {
        config()->set('kinetix.spotlight.limit', 2);

        foreach (range(1, 5) as $i) {
            SpotProduct::create(['name' => "Widget {$i}"]);
        }

        $source = SpotlightResource::make(SpotProduct::class)->searchColumns(['name'])->group('Products');

        $this->assertCount(2, $source->search('widget'));

        // An explicit per-source limit still wins.
        $this->assertCount(4, $source->limit(4)->search('widget'));
    }

    public function test_the_buffer_pages_until_the_limit_is_filled(): void
    {
        Gate::policy(SpotProduct::class, SpotProductPolicy::class);
        $this->actingAs(SpotUser::create(['name' => 'User']));

        // 10 hidden rows ahead of 3 visible ones: the old fixed 3x over-fetch
        // (9 rows) would see nothing but rejections and return an empty list.
        foreach (range(1, 10) as $i) {
            SpotProduct::create(['name' => "Widget hidden {$i}", 'visible' => false]);
        }

        foreach (range(1, 3) as $i) {
            SpotProduct::create(['name' => "Widget visible {$i}", 'visible' => true]);
        }

        $items = SpotlightResource::make(SpotProduct::class)
            ->searchColumns(['name'])
            ->limit(3)
            ->search('widget');

        $this->assertCount(3, $items);
    }

    public function test_trust_query_skips_the_per_record_policy_pass(): void
    {
        Gate::policy(SpotProduct::class, SpotProductPolicy::class);
        $this->actingAs(SpotUser::create(['name' => 'User']));

        SpotProduct::create(['name' => 'Hidden Widget', 'visible' => false]);

        $source = SpotlightResource::make(SpotProduct::class)->searchColumns(['name']);

        // The policy hides it…
        $this->assertCount(0, $source->search('widget'));

        // …unless the source declares its own query authorization-complete.
        $this->assertCount(1, $source->trustQuery()->search('widget'));
    }

    public function test_priority_orders_the_groups(): void
    {
        SpotProduct::create(['name' => 'Widget']);

        KinetixSpotlight::register([
            // Registered first, but the link outranks it.
            SpotlightResource::make(SpotProduct::class)->searchColumns(['name'])->group('Products'),
            SpotlightLink::make('Widget settings')->url('/settings')->group('Navigation')->priority(10),
        ]);

        $labels = collect($this->search('widget')['groups'])->pluck('label');

        $this->assertSame(['Navigation', 'Products'], $labels->all());
    }

    public function test_prefix_matches_float_above_infix_ones(): void
    {
        SpotProduct::create(['name' => 'Super gadget']);
        SpotProduct::create(['name' => 'Gadget one']);

        $items = SpotlightResource::make(SpotProduct::class)
            ->searchColumns(['name'])
            ->search('gadget');

        // Both match; the one that STARTS with the term comes first, even
        // though the other was inserted (and therefore keyed) earlier.
        $this->assertSame('Gadget one', $items[0]->title);
        $this->assertSame('Super gadget', $items[1]->title);
    }
}
