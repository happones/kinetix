<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Actions\DeleteAction;
use Happones\Kinetix\Actions\EditAction;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

class AuthUser extends Authenticatable {}

class AuthWidget extends Model
{
    protected $table = 'auth_widgets';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['editable' => 'bool'];
}

class ActionAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('auth_widgets', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->boolean('editable')->default(false);
        });

        // Policy abilities: update only when the record is editable; delete never.
        Gate::define('update', fn ($user, AuthWidget $widget) => $widget->editable);
        Gate::define('delete', fn ($user, AuthWidget $widget) => false);

        $this->actingAs(new AuthUser);
    }

    public function test_edit_action_is_authorized_for_an_editable_record(): void
    {
        $widget = AuthWidget::create(['name' => 'A', 'editable' => true]);

        $data = EditAction::make()->toData($widget);

        $this->assertNotNull($data);
        $this->assertSame('edit', $data->icon);
    }

    public function test_edit_action_is_hidden_for_a_non_editable_record(): void
    {
        $widget = AuthWidget::create(['name' => 'B', 'editable' => false]);

        $this->assertNull(EditAction::make()->toData($widget));
    }

    public function test_delete_action_is_always_denied(): void
    {
        $widget = AuthWidget::create(['name' => 'C', 'editable' => true]);

        $this->assertNull(DeleteAction::make()->toData($widget));
    }

    public function test_hidden_and_visible_overrides(): void
    {
        $this->assertNull(Action::make('x')->hidden()->toData());
        $this->assertNull(Action::make('x')->visible(false)->toData());
        $this->assertNotNull(Action::make('x')->visible(true)->toData());
    }

    public function test_authorize_closure_override(): void
    {
        $this->assertNull(Action::make('x')->authorize(fn () => false)->toData());
        $this->assertNotNull(Action::make('x')->authorize(fn () => true)->toData());
    }

    public function test_to_array_many_drops_unauthorized_actions(): void
    {
        $widget = AuthWidget::create(['name' => 'D', 'editable' => true]);

        $serialized = Action::toArrayMany([EditAction::make(), DeleteAction::make()], $widget);

        // Edit allowed, delete denied.
        $this->assertCount(1, $serialized);
        $this->assertSame('edit', $serialized[0]['name']);
    }

    public function test_table_filters_record_actions_per_row(): void
    {
        AuthWidget::create(['name' => 'editable', 'editable' => true]);
        AuthWidget::create(['name' => 'locked', 'editable' => false]);

        $data = Table::make(AuthWidget::query()->orderBy('name'))
            ->columns([TextColumn::make('name')])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toData();

        $byName = [];
        foreach ($data->records as $row) {
            $byName[$row->values['name']] = count($row->actions);
        }

        // 'editable' row keeps Edit (Delete denied); 'locked' row has none.
        $this->assertSame(1, $byName['editable']);
        $this->assertSame(0, $byName['locked']);
    }
}
