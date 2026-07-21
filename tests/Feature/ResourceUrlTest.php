<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Resources\Resource;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Router;

class UrlGadget extends Model
{
    protected $table = 'url_gadgets';

    public $timestamps = false;

    protected $guarded = [];
}

class UrlGadgetResource extends Resource
{
    protected static ?string $model = UrlGadget::class;
}

class TeamUrlGadget extends Model
{
    protected $table = 'team_url_gadgets';

    public $timestamps = false;

    protected $guarded = [];
}

class TeamUrlGadgetResource extends Resource
{
    protected static ?string $model = TeamUrlGadget::class;
}

/**
 * Resource::getUrl() hands controllers ready-made operation URLs — filling the
 * record's route key and the `{current_team}` segment — so the generated Vue
 * pages never rebuild (team-scoped) routes client-side.
 */
class ResourceUrlTest extends TestCase
{
    /**
     * @param Router $router
     */
    protected function defineRoutes($router): void
    {
        $router->get('url-gadgets', fn () => 'ok')->name('url-gadgets.index');
        $router->post('url-gadgets', fn () => 'ok')->name('url-gadgets.store');
        $router->put('url-gadgets/{url_gadget}', fn () => 'ok')->name('url-gadgets.update');

        $router->prefix('{current_team}')->group(function () use ($router) {
            $router->get('team-url-gadgets', fn () => 'ok')->name('team-url-gadgets.index');
            $router->post('team-url-gadgets', fn () => 'ok')->name('team-url-gadgets.store');
            $router->put('team-url-gadgets/{team_url_gadget}', fn () => 'ok')->name('team-url-gadgets.update');

            // Probe: getUrl() runs inside a request that carries the team
            // segment, like the generated create()/edit() controller methods.
            $router->get('team-url-probe/{id}', function (string $current_team, string $id) {
                $record     = new TeamUrlGadget;
                $record->id = (int) $id;

                return response()->json([
                    'index'  => TeamUrlGadgetResource::getUrl('index'),
                    'store'  => TeamUrlGadgetResource::getUrl('store'),
                    'update' => TeamUrlGadgetResource::getUrl('update', $record),
                ]);
            });
        });
    }

    public function test_get_url_builds_operation_urls_without_teams(): void
    {
        $record     = new UrlGadget;
        $record->id = 7;

        $this->assertStringEndsWith('/url-gadgets', UrlGadgetResource::getUrl('index'));
        $this->assertStringEndsWith('/url-gadgets', UrlGadgetResource::getUrl('store'));
        $this->assertStringEndsWith('/url-gadgets/7', UrlGadgetResource::getUrl('update', $record));
    }

    public function test_get_url_falls_back_to_the_current_url_for_unregistered_routes(): void
    {
        // No `url-gadgets.destroy` route is registered.
        $this->assertSame(request()->fullUrl(), UrlGadgetResource::getUrl('destroy'));
    }

    public function test_get_url_fills_the_current_team_segment(): void
    {
        $payload = $this->getJson('/acme/team-url-probe/7')->assertOk()->json();

        $this->assertStringEndsWith('/acme/team-url-gadgets', $payload['index']);
        $this->assertStringEndsWith('/acme/team-url-gadgets', $payload['store']);
        $this->assertStringEndsWith('/acme/team-url-gadgets/7', $payload['update']);
    }
}
