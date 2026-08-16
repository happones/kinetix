<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Spotlight\SpotlightResource;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Laravel\Scout\ScoutServiceProvider;
use Laravel\Scout\Searchable;

class ScoutPost extends Model
{
    use Searchable;

    protected $table = 'posts';

    protected $guarded = [];

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return ['title' => $this->title, 'team_id' => $this->team_id];
    }
}

class SpotlightScoutTest extends TestCase
{
    /**
     * @param  Application              $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), ScoutServiceProvider::class];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.spotlight.enabled', true);
        $app['config']->set('kinetix.spotlight.driver', 'auto');
        // In-DB search, no external engine.
        $app['config']->set('scout.driver', 'collection');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('posts', function ($table) {
            $table->increments('id');
            $table->string('title');
            $table->unsignedInteger('team_id')->default(1);
            $table->timestamps();
        });
    }

    public function test_searchable_model_routes_through_scout(): void
    {
        ScoutPost::create(['title' => 'Hello World']);
        ScoutPost::create(['title' => 'Something Else']);

        $resource = SpotlightResource::make(ScoutPost::class)
            ->titleAttribute('title')
            ->group('Posts');

        $items = $resource->search('hello');

        $this->assertCount(1, $items);
        $this->assertSame('Hello World', $items[0]->title);
    }

    public function test_the_source_query_scopes_the_scout_path_too(): void
    {
        // The regression this test exists for: `query()` is how a multi-tenant
        // host binds a source to the active team. It used to be honored on the
        // LIKE branch and dropped on the Scout one, so a model adopting
        // `Searchable` — a change made in another file, for another reason —
        // silently made every tenant's records searchable by every other one.
        ScoutPost::create(['title' => 'Quarterly report', 'team_id' => 1]);
        ScoutPost::create(['title' => 'Quarterly report', 'team_id' => 2]);

        $items = SpotlightResource::make(ScoutPost::class)
            ->titleAttribute('title')
            ->query(fn () => ScoutPost::query()->where('team_id', 1))
            ->search('quarterly');

        $this->assertCount(1, $items);
        $this->assertSame(1, ScoutPost::find($items[0]->id)->team_id);
    }

    public function test_scout_where_filters_engine_side(): void
    {
        // Constraining only the hydration query is correct but under-fills: the
        // engine spends its buffer before that filter runs. The one row this
        // source may return is deliberately the LAST the engine would propose,
        // so it falls outside the buffer unless the filter reaches the engine.
        ScoutPost::create(['title' => 'Report mine', 'team_id' => 1]);

        foreach (range(1, 5) as $i) {
            ScoutPost::create(['title' => "Report {$i}", 'team_id' => 2]);
        }

        $source = fn (): SpotlightResource => SpotlightResource::make(ScoutPost::class)
            ->titleAttribute('title')
            ->limit(3)
            ->query(fn () => ScoutPost::query()->where('team_id', 1));

        // Scoped, therefore safe — but empty, because the engine proposed
        // three rows this source is not allowed to return.
        $this->assertCount(0, $source()->search('report'));

        // Filtered engine-side, the buffer is spent on rows that survive.
        $items = $source()->scoutWhere(['team_id' => 1])->search('report');

        $this->assertCount(1, $items);
        $this->assertSame('Report mine', $items[0]->title);
    }
}
