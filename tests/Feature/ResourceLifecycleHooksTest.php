<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Resources\Resource;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class HookedWidget extends Model
{
    protected $table = 'hooked_widgets';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * A resource that records every lifecycle hook it receives into a static log,
 * so a test can assert both that a hook fired and that it saw the persisted
 * record (an id for create/update, still-present for delete).
 */
class HookedWidgetResource extends Resource
{
    protected static ?string $model = HookedWidget::class;

    /** @var array<int, string> */
    public static array $log = [];

    public static function reset(): void
    {
        static::$log = [];
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([TextInput::make('name')->required()]);
    }

    public static function mutateFormDataBeforeCreate(array $data): array
    {
        static::$log[] = 'mutateFormDataBeforeCreate';
        $data['name']  = $data['name'].' (created)';

        return $data;
    }

    public static function mutateFormDataBeforeUpdate(array $data, Model $record): array
    {
        static::$log[] = 'mutateFormDataBeforeUpdate';
        $data['name']  = $data['name'].' (updated)';

        return $data;
    }

    public static function afterCreate(Model $record): void
    {
        static::$log[] = 'afterCreate:'.($record->exists ? 'persisted' : 'transient');
    }

    public static function afterUpdate(Model $record): void
    {
        static::$log[] = 'afterUpdate';
    }

    public static function afterSave(Model $record): void
    {
        static::$log[] = 'afterSave';
    }

    public static function beforeDelete(Model $record): void
    {
        static::$log[] = 'beforeDelete:'.($record->exists ? 'present' : 'gone');
    }

    public static function afterDelete(Model $record): void
    {
        static::$log[] = 'afterDelete';
    }
}

/**
 * A resource whose afterCreate throws, to prove the write is transactional.
 */
class ThrowingHookWidgetResource extends HookedWidgetResource
{
    public static function afterCreate(Model $record): void
    {
        throw new \RuntimeException('abort');
    }
}

class ResourceLifecycleHooksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('hooked_widgets', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });

        HookedWidgetResource::reset();
    }

    private function token(): string
    {
        return Table::make(HookedWidget::query())
            ->recordModals(HookedWidgetResource::class)
            ->toData()
            ->recordModals
            ->token;
    }

    public function test_create_fires_before_and_after_hooks_in_order(): void
    {
        $this->post(route('kinetix.tables.record.store'), [
            'token' => $this->token(),
            'data'  => ['name' => 'Widget'],
        ])->assertRedirect();

        $this->assertSame(
            ['mutateFormDataBeforeCreate', 'afterCreate:persisted', 'afterSave'],
            HookedWidgetResource::$log,
        );

        // mutateFormDataBeforeCreate ran and its mutation was persisted.
        $this->assertDatabaseHas('hooked_widgets', ['name' => 'Widget (created)']);
    }

    public function test_update_fires_before_and_after_hooks_in_order(): void
    {
        $widget = HookedWidget::create(['name' => 'Original']);
        HookedWidgetResource::reset();

        $this->put(route('kinetix.tables.record.update'), [
            'token' => $this->token(),
            'id'    => $widget->id,
            'data'  => ['name' => 'Changed'],
        ])->assertRedirect();

        $this->assertSame(
            ['mutateFormDataBeforeUpdate', 'afterUpdate', 'afterSave'],
            HookedWidgetResource::$log,
        );

        $this->assertSame('Changed (updated)', HookedWidget::find($widget->id)->name);
    }

    public function test_delete_fires_before_and_after_hooks_in_order(): void
    {
        $widget = HookedWidget::create(['name' => 'Doomed']);
        HookedWidgetResource::reset();

        $this->delete(route('kinetix.tables.record.destroy'), [
            'token' => $this->token(),
            'id'    => $widget->id,
        ])->assertRedirect();

        // beforeDelete saw the record still present; afterDelete fired after.
        $this->assertSame(
            ['beforeDelete:present', 'afterDelete'],
            HookedWidgetResource::$log,
        );

        $this->assertDatabaseMissing('hooked_widgets', ['id' => $widget->id]);
    }

    public function test_a_throwing_after_create_hook_rolls_the_write_back(): void
    {
        $token = Table::make(HookedWidget::query())
            ->recordModals(ThrowingHookWidgetResource::class)
            ->toData()
            ->recordModals
            ->token;

        try {
            $this->post(route('kinetix.tables.record.store'), [
                'token' => $token,
                'data'  => ['name' => 'Rollback'],
            ]);
        } catch (\RuntimeException $e) {
            $this->assertSame('abort', $e->getMessage());
        }

        // The transaction rolled back — nothing was persisted.
        $this->assertDatabaseCount('hooked_widgets', 0);
    }
}
