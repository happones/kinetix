<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Api\ApiLog;
use Happones\Kinetix\Api\Middleware\LogApiRequest;
use Happones\Kinetix\Tests\Concerns\ResolvesTeamRoutes;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionServiceProvider;
use Symfony\Component\HttpFoundation\Response;

class ApiTeam extends Model
{
    protected $table = 'teams';

    public $timestamps = false;

    protected $guarded = [];
}

class ApiCaller extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return BelongsTo<ApiTeam, $this>
     */
    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(ApiTeam::class, 'current_team_id');
    }
}

/**
 * API logs carry paths, tokens and optionally request/response bodies, and were
 * one shared pool: any team's `viewKinetixApiLogs` holder read every tenant's
 * traffic. Rows are now attributed to the caller's team and the viewer scopes
 * strictly — NULL means *unattributed*, never *shared*.
 */
class ApiLogTeamScopingTest extends TestCase
{
    use ResolvesTeamRoutes;

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
        $app['config']->set('kinetix.teams', true);
        $app['config']->set('kinetix.api_logs.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->unsignedInteger('current_team_id')->nullable();
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        (require __DIR__.'/../../database/migrations/2026_01_01_000018_create_kinetix_api_logs_table.php')->up();
        (require __DIR__.'/../../database/migrations/2026_01_01_000026_add_team_id_to_kinetix_api_logs_table.php')->up();

        Gate::define('viewKinetixApiLogs', fn (mixed $user = null): bool => true);

        ApiTeam::create(['id' => 7, 'name' => 'Seven']);
        ApiTeam::create(['id' => 8, 'name' => 'Eight']);
    }

    private function log(?int $teamId, string $path): ApiLog
    {
        return ApiLog::create([
            'team_id'    => $teamId,
            'method'     => 'GET',
            'path'       => $path,
            'status'     => 200,
            'created_at' => now(),
        ]);
    }

    public function test_the_viewer_only_shows_the_active_teams_traffic(): void
    {
        $this->log(7, '/api/v1/ours');
        $this->log(8, '/api/v1/theirs');

        $paths = array_column(
            $this->getJson('/7/_kinetix/api-logs')->assertOk()->json('data'),
            'path',
        );

        $this->assertSame(['/api/v1/ours'], $paths);
    }

    public function test_unattributed_rows_are_not_shared_across_teams(): void
    {
        // Written before the migration: they must not surface inside a tenant.
        $this->log(null, '/api/v1/legacy');
        $this->log(7, '/api/v1/ours');

        $paths = array_column(
            $this->getJson('/7/_kinetix/api-logs')->assertOk()->json('data'),
            'path',
        );

        $this->assertSame(['/api/v1/ours'], $paths);
    }

    public function test_a_logged_request_is_attributed_to_the_callers_team(): void
    {
        // A token-authenticated API route has no team segment, so the tenant
        // comes from the caller.
        $this->withoutTeamSegment();

        $caller = ApiCaller::create(['id' => 1, 'name' => 'Integration', 'current_team_id' => 8]);
        $this->actingAs($caller);

        $this->terminateFor('/api/v1/orders');

        $this->assertSame(8, (int) ApiLog::query()->firstOrFail()->team_id);
    }

    public function test_a_team_segment_on_the_api_route_wins(): void
    {
        $caller = ApiCaller::create(['id' => 1, 'name' => 'Integration', 'current_team_id' => 8]);
        $this->actingAs($caller);

        $this->withTeamSegment(7);
        $this->terminateFor('/api/v1/orders');

        $this->assertSame(7, (int) ApiLog::query()->firstOrFail()->team_id);
    }

    public function test_no_team_column_is_written_in_a_single_tenant_app(): void
    {
        config()->set('kinetix.teams', false);
        config()->set('kinetix.api_logs.teams', null);

        $this->assertSame([], ApiLog::teamAttributes());
    }

    public function test_the_scope_is_a_no_op_without_teams(): void
    {
        config()->set('kinetix.api_logs.teams', false);

        $this->log(7, '/api/v1/ours');
        $this->log(8, '/api/v1/theirs');
        $this->log(null, '/api/v1/legacy');

        $this->assertCount(3, ApiLog::query()->forCurrentTeam()->get());
    }

    /**
     * Drive the middleware's terminate() the way the framework does.
     */
    private function terminateFor(string $path): void
    {
        $request = Request::create($path, 'GET');
        $request->setUserResolver(fn () => auth()->user());

        (new LogApiRequest)->terminate($request, new Response('', 200));
    }
}
