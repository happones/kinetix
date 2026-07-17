<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Infolists\Components\TextEntry;
use Happones\Kinetix\Infolists\Infolist;
use Happones\Kinetix\Resources\Resource;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class RecordModalWidget extends Model
{
    protected $table = 'record_modal_widgets';

    public $timestamps = false;

    protected $guarded = [];
}

class RecordModalWidgetResource extends Resource
{
    protected static ?string $model = RecordModalWidget::class;

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([TextInput::make('name')->required()]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([TextEntry::make('name')]);
    }
}

class RecordModalCrudTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('record_modal_widgets', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });

        RecordModalWidget::create(['name' => 'Original']);
    }

    private function token(): string
    {
        return Table::make(RecordModalWidget::query())
            ->recordModals(RecordModalWidgetResource::class)
            ->toData()
            ->recordModals
            ->token;
    }

    public function test_record_modals_descriptor_is_serialized(): void
    {
        $data = Table::make(RecordModalWidget::query())
            ->recordModals(RecordModalWidgetResource::class)
            ->toData();

        $this->assertNotNull($data->recordModals);
        $this->assertTrue($data->recordModals->enabled);
        $this->assertTrue($data->recordModals->hasForm);
        $this->assertTrue($data->recordModals->hasInfolist);
        $this->assertSame('server', $data->recordModals->source);
        $this->assertIsArray($data->recordModals->createForm);
        // Plain tables carry no descriptor.
        $this->assertNull(Table::make(RecordModalWidget::query())->toData()->recordModals);
    }

    public function test_resolve_returns_a_fresh_filled_form_for_edit(): void
    {
        $this->postJson(route('kinetix.tables.record.resolve'), [
            'token' => $this->token(),
            'mode'  => 'edit',
            'id'    => 1,
        ])
            ->assertOk()
            ->assertJsonPath('form.data.name', 'Original')
            ->assertJsonPath('form.operation', 'edit');
    }

    public function test_resolve_returns_the_infolist_for_view(): void
    {
        $this->postJson(route('kinetix.tables.record.resolve'), [
            'token' => $this->token(),
            'mode'  => 'view',
            'id'    => 1,
        ])
            ->assertOk()
            ->assertJsonPath('infolist.schema.0.state', 'Original');
    }

    public function test_store_creates_a_record(): void
    {
        $this->post(route('kinetix.tables.record.store'), [
            'token' => $this->token(),
            'data'  => ['name' => 'Created'],
        ])->assertRedirect();

        $this->assertDatabaseHas('record_modal_widgets', ['name' => 'Created']);
    }

    public function test_update_persists_changes(): void
    {
        $this->put(route('kinetix.tables.record.update'), [
            'token' => $this->token(),
            'id'    => 1,
            'data'  => ['name' => 'Updated'],
        ])->assertRedirect();

        $this->assertSame('Updated', RecordModalWidget::find(1)->name);
    }

    public function test_destroy_deletes_the_record(): void
    {
        $this->delete(route('kinetix.tables.record.destroy'), [
            'token' => $this->token(),
            'id'    => 1,
        ])->assertRedirect();

        $this->assertDatabaseMissing('record_modal_widgets', ['id' => 1]);
    }

    public function test_a_tampered_token_is_rejected(): void
    {
        $this->postJson(route('kinetix.tables.record.resolve'), [
            'token' => 'not-a-valid-token',
            'mode'  => 'edit',
            'id'    => 1,
        ])->assertStatus(400);
    }

    public function test_store_validates_through_the_resource_form(): void
    {
        // `name` is required; omitting it triggers the resource form's validation,
        // which redirects back without writing anything.
        $this->post(route('kinetix.tables.record.store'), [
            'token' => $this->token(),
            'data'  => [],
        ])->assertRedirect();

        $this->assertDatabaseCount('record_modal_widgets', 1);
    }
}
