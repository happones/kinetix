<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Exports\Exporter;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Imports\ImportController;
use Happones\Kinetix\Resources\Resource;
use Happones\Kinetix\Support\DescriptorAudience;
use Happones\Kinetix\Support\SignedDescriptor;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

class DAOrder extends Model
{
    protected $table = 'da_orders';

    public $timestamps = false;

    protected $guarded = [];

    public function items(): HasMany
    {
        return $this->hasMany(DAItem::class, 'order_id');
    }
}

class DAItem extends Model
{
    protected $table = 'da_items';

    public $timestamps = false;

    protected $guarded = [];
}

class DAUser extends Authenticatable
{
    protected $table = 'da_users';

    public $timestamps = false;

    protected $guarded = [];
}

class DAItemResource extends Resource
{
    protected static ?string $model = DAItem::class;

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([TextInput::make('name')]);
    }
}

class DAItemExporter extends Exporter
{
    protected static ?string $model = DAItem::class;

    public static function getColumns(): array
    {
        return [];
    }
}

/**
 * Every signed descriptor names the endpoint family it was minted for, and
 * every endpoint accepts only its own — a token whose payload happens to have
 * the right shape for another endpoint is refused there.
 */
class DescriptorAudienceTest extends TestCase
{
    private DAOrder $order;

    private DAItem $item;

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', DAUser::class);
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('da_users', function (Blueprint $table) {
            $table->increments('id');
        });
        Schema::create('da_orders', function (Blueprint $table) {
            $table->increments('id');
        });
        Schema::create('da_items', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('order_id')->nullable();
            $table->string('name')->nullable();
        });

        $this->order = DAOrder::create([]);
        $this->item  = $this->order->items()->create(['name' => 'Keep me']);

        $this->actingAs(DAUser::create([]));
    }

    /**
     * What a relation manager mints for the order's items.
     */
    private function relationManagerToken(): string
    {
        return SignedDescriptor::seal([
            'parent'   => DAOrder::class,
            'key'      => $this->order->getKey(),
            'relation' => 'items',
            'manager'  => 'App\\Kinetix\\ItemsRelationManager',
        ], DescriptorAudience::RelationManager);
    }

    public function test_a_relation_manager_descriptor_cannot_delete_through_the_table_repeater(): void
    {
        $this->deleteJson('/_kinetix/tables/table-repeater', [
            'token' => $this->relationManagerToken(),
            'id'    => $this->item->getKey(),
        ])->assertForbidden();

        $this->assertNotNull($this->item->fresh());
    }

    public function test_a_relation_manager_descriptor_cannot_create_through_the_table_repeater(): void
    {
        $this->postJson('/_kinetix/tables/table-repeater', [
            'token'  => $this->relationManagerToken(),
            'values' => ['name' => 'Injected'],
        ])->assertForbidden();

        $this->assertSame(1, DAItem::query()->count());
    }

    public function test_a_write_descriptor_is_refused_by_the_record_modals(): void
    {
        $token = SignedDescriptor::seal([
            'model'    => DAItem::class,
            'columns'  => ['name' => []],
            'reorder'  => null,
            'resource' => DAItemResource::class,
            'scope'    => [],
            'relation' => null,
            'ability'  => null,
        ], DescriptorAudience::TableWrite);

        $this->postJson(route('kinetix.tables.record.store'), ['token' => $token, 'data' => ['name' => 'New']])
            ->assertForbidden();
        $this->putJson(route('kinetix.tables.record.update'), ['token' => $token, 'id' => $this->item->getKey(), 'data' => ['name' => 'Changed']])
            ->assertForbidden();
        $this->deleteJson(route('kinetix.tables.record.destroy'), ['token' => $token, 'id' => $this->item->getKey()])
            ->assertForbidden();

        $this->assertSame(['Keep me'], DAItem::query()->pluck('name')->all());
    }

    public function test_a_descriptor_without_an_audience_is_refused(): void
    {
        $token = Crypt::encrypt([
            'model'    => DAItem::class,
            'resource' => DAItemResource::class,
            'user'     => auth()->id(),
            'team'     => null,
            'expires'  => null,
        ]);

        $this->postJson(route('kinetix.tables.record.store'), ['token' => $token, 'data' => ['name' => 'New']])
            ->assertForbidden()
            ->assertJsonPath('message', __('kinetix.form_session_expired'));

        $this->assertSame(1, DAItem::query()->count());
    }

    /**
     * Each endpoint, presented with a payload shaped for it but sealed for one
     * other audience, refuses it before acting on it.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: DescriptorAudience, 4: string}>
     */
    public static function consumers(): array
    {
        return [
            'search'           => ['post', 'kinetix.forms.search', 'token', DescriptorAudience::Upload, 'search_forbidden'],
            'recompute'        => ['post', 'kinetix.forms.recompute', 'descriptor', DescriptorAudience::Search, 'table_write_forbidden'],
            'upload'           => ['post', 'kinetix.uploads.store', 'token', DescriptorAudience::FormRecompute, 'upload_invalid_field'],
            'aggregates'       => ['post', 'kinetix.tables.aggregates', 'descriptor', DescriptorAudience::TableWrite, 'table_write_forbidden'],
            'calendar move'    => ['post', 'kinetix.tables.calendar-move', 'model', DescriptorAudience::KanbanMove, 'table_write_forbidden'],
            'kanban move'      => ['post', 'kinetix.tables.kanban-move', 'model', DescriptorAudience::CalendarMove, 'table_write_forbidden'],
            'form action'      => ['post', 'kinetix.tables.form-action', 'descriptor', DescriptorAudience::TableBulk, 'table_write_forbidden'],
            'bulk action'      => ['post', 'kinetix.tables.bulk-action', 'descriptor', DescriptorAudience::TableFormAction, 'table_write_forbidden'],
            'cell update'      => ['post', 'kinetix.tables.cell-update', 'model', DescriptorAudience::TableBulk, 'table_write_forbidden'],
            'record modals'    => ['post', 'kinetix.tables.record.store', 'token', DescriptorAudience::TableWrite, 'table_write_forbidden'],
            'relation manager' => ['post', 'kinetix.relations.record.store', 'descriptor', DescriptorAudience::TableRepeater, 'table_write_forbidden'],
            'table repeater'   => ['post', 'kinetix.table-repeater.store', 'token', DescriptorAudience::RelationManager, 'table_write_forbidden'],
        ];
    }

    #[DataProvider('consumers')]
    public function test_each_endpoint_refuses_a_foreign_audience(string $verb, string $route, string $field, DescriptorAudience $foreign, string $message): void
    {
        $token = SignedDescriptor::seal([
            'model'       => DAItem::class,
            'parent'      => DAOrder::class,
            'key'         => $this->order->getKey(),
            'relation'    => 'items',
            'columns'     => ['name'],
            'resource'    => DAItemResource::class,
            'manager'     => 'App\\Kinetix\\ItemsRelationManager',
            'label'       => 'name',
            'value'       => 'id',
            'scope'       => [],
            'bulk'        => [],
            'forms'       => ['record' => [], 'toolbar' => []],
            'queryPrefix' => null,
        ], $foreign);

        $response = $this->withHeaders(['Accept' => 'application/json'])->{$verb}(route($route), [
            $field   => $token,
            'values' => ['name' => 'x'],
            'data'   => ['name' => 'x'],
            'file'   => UploadedFile::fake()->create('probe.txt', 1),
        ]);

        $response->assertForbidden()->assertJsonPath('message', __('kinetix.'.$message));
        $this->assertSame(1, DAItem::query()->count());
        $this->assertSame('Keep me', $this->item->fresh()?->name);
    }

    public function test_the_address_locations_endpoint_refuses_a_foreign_audience(): void
    {
        config()->set('kinetix.locations.providers.probe', ['driver' => 'unused']);

        $token = SignedDescriptor::seal(['locations' => 'probe'], DescriptorAudience::Search);

        $this->getJson(route('kinetix.forms.locations', ['token' => $token, 'level' => 'countries']))
            ->assertForbidden();
    }

    public function test_a_class_token_must_be_minted_as_one(): void
    {
        $foreign = SignedDescriptor::seal(['class' => DAItemExporter::class], DescriptorAudience::TableWrite);

        $this->assertNull(SignedDescriptor::classFrom($foreign, Exporter::class));
        $this->assertSame(DAItemExporter::class, SignedDescriptor::classFrom(DAItemExporter::token(), Exporter::class));
    }

    public function test_an_export_relation_scope_must_come_from_a_relation_manager(): void
    {
        $foreign = SignedDescriptor::seal([
            'parent'   => DAOrder::class,
            'key'      => $this->order->getKey(),
            'relation' => 'items',
            'columns'  => ['name'],
        ], DescriptorAudience::TableRepeater);

        $this->postJson(route('kinetix.exports.start'), [
            'exporter' => DAItemExporter::token(),
            'format'   => 'csv',
            'relation' => $foreign,
        ])->assertForbidden();
    }

    public function test_an_import_file_token_must_be_minted_as_one(): void
    {
        $foreign = SignedDescriptor::seal(['path' => 'kinetix-imports/sample.csv'], DescriptorAudience::Upload);

        $controller = app(ImportController::class);
        $resolve    = \Closure::bind(fn (string $token): string => $this->resolvePath($token), $controller, $controller);

        $this->expectException(\RuntimeException::class);

        $resolve($foreign);
    }
}
