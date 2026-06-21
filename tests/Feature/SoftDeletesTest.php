<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Actions\ForceDeleteAction;
use Happones\Kinetix\Actions\RestoreAction;
use Happones\Kinetix\Tables\Filters\TrashedFilter;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

class TrashUser extends Authenticatable {}

class TrashWidget extends Model
{
    use SoftDeletes;

    protected $table = 'trash_widgets';

    public $timestamps = false;

    protected $guarded = [];

    protected $dates = ['deleted_at'];
}

class SoftDeletesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('trash_widgets', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->softDeletes();
        });

        TrashWidget::create(['name' => 'A']);
        TrashWidget::create(['name' => 'B']);
        $trashed = TrashWidget::create(['name' => 'C']);
        $trashed->delete(); // soft delete

        Gate::define('restore', fn ($user, $widget) => true);
        Gate::define('forceDelete', fn ($user, $widget) => true);
        $this->actingAs(new TrashUser());
    }

    public function test_trashed_filter_default_shows_only_active(): void
    {
        $query = TrashWidget::query();
        TrashedFilter::make()->apply($query, '');

        $this->assertSame(2, $query->count());
    }

    public function test_trashed_filter_with_includes_trashed(): void
    {
        $query = TrashWidget::query();
        TrashedFilter::make()->apply($query, 'with');

        $this->assertSame(3, $query->count());
    }

    public function test_trashed_filter_only_shows_trashed(): void
    {
        $query = TrashWidget::query();
        TrashedFilter::make()->apply($query, 'only');

        $this->assertSame(['C'], $query->pluck('name')->all());
    }

    public function test_restore_action_only_visible_for_trashed_records(): void
    {
        $active = TrashWidget::first();
        $trashed = TrashWidget::onlyTrashed()->first();

        $this->assertNull(RestoreAction::make()->toData($active));
        $this->assertNotNull(RestoreAction::make()->toData($trashed));
    }

    public function test_force_delete_action_only_visible_for_trashed_records(): void
    {
        $active = TrashWidget::first();
        $trashed = TrashWidget::onlyTrashed()->first();

        $this->assertNull(ForceDeleteAction::make()->toData($active));

        $data = ForceDeleteAction::make()->toData($trashed);
        $this->assertNotNull($data);
        $this->assertTrue($data->requiresConfirmation);
    }
}
