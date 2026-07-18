<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;

class ActionRouteWidget extends Model
{
    protected $table = 'widgets';

    public $timestamps = false;

    protected $guarded = [];
}

class ActionRouteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('widgets', fn () => '')->name('widgets.index');
        Route::get('widgets/create', fn () => '')->name('widgets.create');
        Route::get('widgets/{widget}/edit', fn () => '')->name('widgets.edit');
        Route::delete('widgets/{widget}', fn () => '')->name('widgets.destroy');

        // Names attached after add() aren't in the lookup until it's refreshed
        // (the framework does this per request in a real app).
        Route::getRoutes()->refreshNameLookups();
    }

    private function record(): ActionRouteWidget
    {
        $w     = new ActionRouteWidget;
        $w->id = 7;

        return $w;
    }

    public function test_route_resolves_a_per_record_url(): void
    {
        $data = Action::make('edit')->route('widgets.edit')->toData($this->record());

        $this->assertNotNull($data);
        $this->assertSame(url('widgets/7/edit'), $data->url);
    }

    public function test_route_resolves_a_static_url_for_recordless_routes(): void
    {
        // A create/toolbar route has no record param, so it resolves once even
        // in the record-less template pass.
        $data = Action::make('create')->route('widgets.create')->toData();

        $this->assertNotNull($data);
        $this->assertSame(url('widgets/create'), $data->url);
    }

    public function test_route_auto_hides_when_the_route_is_not_registered(): void
    {
        // Unwired route → the action is dropped entirely (no dead button).
        $data = Action::make('edit')->route('nonexistent.route')->toData($this->record());

        $this->assertNull($data);
    }

    public function test_route_with_a_verb_performs_an_inertia_visit(): void
    {
        $data = Action::make('delete')->route('widgets.destroy', method: 'delete')->toData($this->record());

        $this->assertNotNull($data);
        $this->assertSame(url('widgets/7'), $data->url);
        $this->assertSame('delete', $data->inertiaVisit['method'] ?? null);
    }

    public function test_record_action_url_is_null_in_the_template_pass(): void
    {
        // With no record (the whole-table "template" serialization), a per-record
        // route must not throw — its URL is simply deferred (null).
        $data = Action::make('edit')->route('widgets.edit')->toData();

        $this->assertNotNull($data);
        $this->assertNull($data->url);
    }
}
