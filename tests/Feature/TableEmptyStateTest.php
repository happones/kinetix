<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Resources\RelationManager;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class TesWidget extends Model
{
    protected $table = 'tes_widgets';

    public $timestamps = false;

    protected $guarded = [];
}

class TesParent extends Model
{
    protected $table = 'tes_parents';

    public $timestamps = false;

    protected $guarded = [];

    public function widgets()
    {
        return $this->hasMany(TesWidget::class, 'parent_id');
    }
}

class TesReadOnlyManager extends RelationManager
{
    protected static bool $readOnly = true;

    protected static string $relationship = 'widgets';

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('name')])
            ->emptyStateHeading('No widgets yet')
            ->emptyStateActions([Action::make('add')->label('Add widget')]);
    }
}

class TableEmptyStateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tes_widgets', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('parent_id')->nullable();
            $table->string('name');
        });

        Schema::create('tes_parents', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });
    }

    public function test_a_configured_empty_state_serializes_with_its_actions(): void
    {
        $data = Table::make(TesWidget::query())
            ->columns([TextColumn::make('name')])
            ->emptyStateHeading('No widgets yet')
            ->emptyStateDescription('Create the first one to get started.')
            ->emptyStateIcon('package')
            ->emptyStateActions([Action::make('create')->label('New widget')])
            ->toData();

        $this->assertNotNull($data->emptyState);
        $this->assertSame('No widgets yet', $data->emptyState->heading);
        $this->assertSame('Create the first one to get started.', $data->emptyState->description);
        $this->assertSame('package', $data->emptyState->icon);
        $this->assertCount(1, $data->emptyState->actions);
        $this->assertSame('create', $data->emptyState->actions[0]->name);
    }

    public function test_an_unconfigured_table_ships_no_empty_state(): void
    {
        $data = Table::make(TesWidget::query())
            ->columns([TextColumn::make('name')])
            ->toData();

        $this->assertNull($data->emptyState);
    }

    public function test_unauthorized_empty_state_actions_are_dropped(): void
    {
        $data = Table::make(TesWidget::query())
            ->columns([TextColumn::make('name')])
            ->emptyStateHeading('No widgets yet')
            ->emptyStateActions([
                Action::make('visible')->label('Visible'),
                Action::make('hidden')->label('Hidden')->authorize(fn (): bool => false),
            ])
            ->toData();

        $this->assertSame(
            ['visible'],
            array_map(fn ($action) => $action->name, $data->emptyState->actions),
        );
    }

    public function test_a_read_only_relation_manager_strips_empty_state_actions(): void
    {
        $parent = TesParent::create(['name' => 'Kinetix']);

        $data = TesReadOnlyManager::make($parent)->toData();

        $this->assertNotNull($data->table->emptyState);
        $this->assertSame('No widgets yet', $data->table->emptyState->heading);
        $this->assertSame([], $data->table->emptyState->actions);
    }
}
