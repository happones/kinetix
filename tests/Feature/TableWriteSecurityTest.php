<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Columns\ToggleColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

class ScopedWidget extends Model
{
    protected $table = 'scoped_widgets';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['is_active' => 'bool'];
}

class ScopedWidgetUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class ScopedWidgetPolicy
{
    public function update(ScopedWidgetUser $user, ScopedWidget $widget): bool
    {
        return $widget->team_id === $user->team_id;
    }
}

/**
 * The table write endpoints (inline cell edits + reordering) trust only the
 * table's signed descriptor, so these tests cover the four axes that make the
 * descriptor safe: tenant scoping, policy authorization, user binding, expiry.
 */
class TableWriteSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('scoped_widgets', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('team_id');
            $table->string('name');
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('position')->default(0);
        });

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('team_id')->nullable();
        });
    }

    /**
     * A descriptor minted for team 1's table, as team 1's user would receive it.
     */
    private function tokenForTeam(int $teamId): string
    {
        return Table::make(ScopedWidget::query()->where('team_id', $teamId))
            ->columns([
                TextColumn::make('name'),
                ToggleColumn::make('is_active'),
            ])
            ->reorderable('position')
            ->toData()
            ->model;
    }

    public function test_a_record_outside_the_tables_scope_cannot_be_edited(): void
    {
        $mine    = ScopedWidget::create(['team_id' => 1, 'name' => 'Mine']);
        $foreign = ScopedWidget::create(['team_id' => 2, 'name' => 'Theirs']);
        $token   = $this->tokenForTeam(1);

        // Sanity: the endpoint does work for a record the table could show.
        $this->postJson(route('kinetix.tables.cell-update'), [
            'model'    => $token,
            'recordId' => $mine->id,
            'column'   => 'is_active',
            'value'    => true,
        ])->assertOk();

        $response = $this->postJson(route('kinetix.tables.cell-update'), [
            'model'    => $token,
            'recordId' => $foreign->id,
            'column'   => 'is_active',
            'value'    => true,
        ]);

        $response->assertNotFound();
        $this->assertFalse($foreign->fresh()->is_active);
    }

    public function test_a_record_outside_the_tables_scope_cannot_be_reordered(): void
    {
        $mine    = ScopedWidget::create(['team_id' => 1, 'name' => 'Mine', 'position' => 1]);
        $foreign = ScopedWidget::create(['team_id' => 2, 'name' => 'Theirs', 'position' => 7]);

        $this->postJson(route('kinetix.tables.reorder'), [
            'model' => $this->tokenForTeam(1),
            'ids'   => [$foreign->id, $mine->id],
        ])->assertOk();

        // The foreign record keeps its position; only the in-scope one moved.
        $this->assertSame(7, $foreign->fresh()->position);
        $this->assertSame(2, $mine->fresh()->position);
    }

    public function test_a_nested_ids_array_cannot_mass_assign_one_position(): void
    {
        $a = ScopedWidget::create(['team_id' => 1, 'name' => 'A', 'position' => 1]);
        $b = ScopedWidget::create(['team_id' => 1, 'name' => 'B', 'position' => 2]);

        $this->postJson(route('kinetix.tables.reorder'), [
            'model' => $this->tokenForTeam(1),
            'ids'   => [[$a->id, $b->id]],
        ])->assertOk();

        $this->assertSame(1, $a->fresh()->position);
        $this->assertSame(2, $b->fresh()->position);
    }

    public function test_the_models_policy_is_enforced_on_a_cell_edit(): void
    {
        Gate::policy(ScopedWidget::class, ScopedWidgetPolicy::class);

        $user   = ScopedWidgetUser::create(['team_id' => 1]);
        $widget = ScopedWidget::create(['team_id' => 2, 'name' => 'Theirs']);

        // The scope is wide open here, so the policy is the only thing standing
        // between the request and another team's row.
        $token = Table::make(ScopedWidget::query())
            ->columns([ToggleColumn::make('is_active')])
            ->toData()
            ->model;

        $response = $this->actingAs($user)->postJson(route('kinetix.tables.cell-update'), [
            'model'    => $token,
            'recordId' => $widget->id,
            'column'   => 'is_active',
            'value'    => true,
        ]);

        $response->assertForbidden();
        $this->assertFalse($widget->fresh()->is_active);
    }

    public function test_a_descriptor_minted_for_another_user_is_rejected(): void
    {
        $owner    = ScopedWidgetUser::create(['team_id' => 1]);
        $attacker = ScopedWidgetUser::create(['team_id' => 2]);
        $widget   = ScopedWidget::create(['team_id' => 1, 'name' => 'Mine']);

        $this->actingAs($owner);

        $token = Table::make(ScopedWidget::query()->where('team_id', 1))
            ->columns([ToggleColumn::make('is_active')])
            ->toData()
            ->model;

        $response = $this->actingAs($attacker)->postJson(route('kinetix.tables.cell-update'), [
            'model'    => $token,
            'recordId' => $widget->id,
            'column'   => 'is_active',
            'value'    => true,
        ]);

        $response->assertForbidden();
        $this->assertFalse($widget->fresh()->is_active);
    }

    public function test_an_expired_descriptor_is_rejected(): void
    {
        config()->set('kinetix.tables.token_ttl', 1);

        $widget = ScopedWidget::create(['team_id' => 1, 'name' => 'Mine']);
        $token  = $this->tokenForTeam(1);

        $this->travel(2)->minutes();

        $response = $this->postJson(route('kinetix.tables.cell-update'), [
            'model'    => $token,
            'recordId' => $widget->id,
            'column'   => 'is_active',
            'value'    => true,
        ]);

        $response->assertForbidden();
        $this->assertFalse($widget->fresh()->is_active);
    }

    public function test_an_array_record_id_is_rejected_without_a_server_error(): void
    {
        ScopedWidget::create(['team_id' => 1, 'name' => 'Mine']);

        $this->postJson(route('kinetix.tables.cell-update'), [
            'model'    => $this->tokenForTeam(1),
            'recordId' => [1, 2],
            'column'   => 'is_active',
            'value'    => true,
        ])->assertNotFound();
    }

    public function test_reordering_is_rejected_when_the_table_is_not_reorderable(): void
    {
        $widget = ScopedWidget::create(['team_id' => 1, 'name' => 'Mine', 'position' => 3]);

        $token = Table::make(ScopedWidget::query()->where('team_id', 1))
            ->columns([TextColumn::make('name')])
            ->toData()
            ->model;

        $this->postJson(route('kinetix.tables.reorder'), [
            'model' => $token,
            'ids'   => [$widget->id],
        ])->assertForbidden();

        $this->assertSame(3, $widget->fresh()->position);
    }

    public function test_reordering_fires_model_events_so_host_observers_still_run(): void
    {
        $widget = ScopedWidget::create(['team_id' => 1, 'name' => 'Mine', 'position' => 5]);

        $saved = 0;
        ScopedWidget::saved(function () use (&$saved): void {
            $saved++;
        });

        $this->postJson(route('kinetix.tables.reorder'), [
            'model' => $this->tokenForTeam(1),
            'ids'   => [$widget->id],
        ])->assertOk();

        $this->assertSame(1, $saved);
        $this->assertSame(1, $widget->fresh()->position);
    }
}
