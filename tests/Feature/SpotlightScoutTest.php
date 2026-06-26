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
        return ['title' => $this->title];
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
}
