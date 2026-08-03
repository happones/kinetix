<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\Concerns\ResolvesTeamRoutes;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class SharedTeam extends Model
{
    protected $table = 'teams';

    public $timestamps = false;

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}

/**
 * `kinetix_config` carries the endpoint prefix (team segment already resolved)
 * and the team route key the app builds its own links from.
 */
class TeamRoutePrefixShareTest extends TestCase
{
    use ResolvesTeamRoutes;

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.teams', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('teams', function (Blueprint $table) {
            $table->increments('id');
            $table->string('slug');
        });
    }

    public function test_the_segment_is_resolved_into_both_the_prefix_and_the_team_key(): void
    {
        $this->withTeamSegment('acme');

        $config = $this->sharedConfig();

        $this->assertSame('acme/_kinetix', $config['route_prefix']);
        $this->assertSame('acme', $config['team']);
    }

    public function test_a_bound_team_model_does_not_blow_up_the_share(): void
    {
        // A host route binding makes the param a MODEL. Interpolating it used to
        // fatal with "Object of class … could not be converted to string" — on
        // every page, since this prop is shared globally.
        $this->withTeamSegment(SharedTeam::create(['id' => 1, 'slug' => 'acme']));

        $config = $this->sharedConfig();

        $this->assertSame('acme/_kinetix', $config['route_prefix']);
        $this->assertSame('acme', $config['team']);
    }

    public function test_the_billing_team_param_is_honored(): void
    {
        $this->withTeamSegment('acme', param: 'team');

        $this->assertSame('acme', $this->sharedConfig()['team']);
    }

    public function test_no_team_key_without_teams(): void
    {
        config()->set('kinetix.teams', false);
        $this->withTeamSegment('acme');

        $config = $this->sharedConfig();

        $this->assertSame('_kinetix', $config['route_prefix']);
        $this->assertNull($config['team']);
    }

    /**
     * @return array{route_prefix: string, team: string|int|null}
     */
    private function sharedConfig(): array
    {
        $shared = Inertia::getShared('kinetix_config');

        /** @var array{route_prefix: string, team: string|int|null} $resolved */
        $resolved = is_callable($shared) ? $shared() : $shared;

        return $resolved;
    }
}
