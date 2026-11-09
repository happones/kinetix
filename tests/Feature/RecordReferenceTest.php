<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Infolists\Components\TextEntry;
use Happones\Kinetix\Infolists\Infolist;
use Happones\Kinetix\Resources\RecordReference;
use Happones\Kinetix\Resources\Resource;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

enum ReferenceChannel: string
{
    case Web = 'web';
}

class ReferencedOrder extends Model
{
    protected $table = 'referenced_orders';

    public $timestamps = false;

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }
}

class ReferencedOrderResource extends Resource
{
    protected static ?string $model = ReferencedOrder::class;

    protected static ?string $recordReferenceAttribute = 'ulid';

    public static function form(Form $form): Form
    {
        return $form->schema([TextInput::make('code')]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([TextEntry::make('code')]);
    }
}

class UnreferencedOrderResource extends Resource
{
    protected static ?string $model = ReferencedOrder::class;

    public static function form(Form $form): Form
    {
        return $form->schema([TextInput::make('code')]);
    }
}

class CustomReferenceOrderResource extends Resource
{
    protected static ?string $model = ReferencedOrder::class;

    protected static ?string $modelLabel = 'Purchase';

    public static function recordReference(): ?RecordReference
    {
        return RecordReference::make('ulid')->first(6)->copyWithLabel(false);
    }
}

class RecordReferenceTest extends TestCase
{
    private const ULID = '01JD8XKQ4M7T2R9VBN3C5H6PZW';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('referenced_orders', function (Blueprint $table) {
            $table->increments('id');
            $table->string('ulid')->nullable();
            $table->string('code')->nullable();
        });
    }

    private function order(?string $ulid = self::ULID): ReferencedOrder
    {
        return ReferencedOrder::create(['ulid' => $ulid, 'code' => 'A-7']);
    }

    public function test_the_attribute_property_shows_the_last_four_with_the_model_label(): void
    {
        $reference = ReferencedOrderResource::getRecordReference($this->order());

        $this->assertNotNull($reference);
        $this->assertArrayNotHasKey('value', $reference->toArray());
        $this->assertSame('6PZW', $reference->visible);
        $this->assertSame('end', $reference->position);
        $this->assertSame('Referenced Order', $reference->label);
        $this->assertSame('Referenced Order: '.self::ULID, $reference->copy);
    }

    public function test_a_resource_without_a_reference_shows_none(): void
    {
        $this->assertNull(UnreferencedOrderResource::recordReference());
        $this->assertNull(UnreferencedOrderResource::getRecordReference($this->order()));
    }

    public function test_an_overridden_reference_uses_its_own_options_and_the_model_label(): void
    {
        $reference = CustomReferenceOrderResource::getRecordReference($this->order());

        $this->assertSame('01JD8X', $reference?->visible);
        $this->assertSame('start', $reference?->position);
        $this->assertSame('Purchase', $reference?->label);
        $this->assertSame(self::ULID, $reference?->copy);
    }

    public function test_a_record_without_a_value_has_no_reference(): void
    {
        $this->assertNull(ReferencedOrderResource::getRecordReference($this->order(null)));
        $this->assertNull(ReferencedOrderResource::getRecordReference($this->order('  ')));
    }

    public function test_a_value_no_longer_than_the_characters_is_shown_whole(): void
    {
        $reference = RecordReference::make('ulid')->last(8)->resolve($this->order('ab-12'));

        $this->assertSame('ab-12', $reference?->visible);
        $this->assertSame('full', $reference?->position);
    }

    public function test_full_shows_the_whole_value(): void
    {
        $reference = RecordReference::make('ulid')->full()->resolve($this->order('summer-sale'), 'Order');

        $this->assertSame('summer-sale', $reference?->visible);
        $this->assertSame('full', $reference?->position);
        $this->assertSame('Order: summer-sale', $reference?->copy);
    }

    public function test_the_label_can_be_set_or_dropped(): void
    {
        $order = $this->order();

        $this->assertSame('Order', RecordReference::make('ulid')->label('Order')->resolve($order, 'Default')?->label);

        $unlabelled = RecordReference::make('ulid')->label(false)->resolve($order, 'Default');
        $this->assertNull($unlabelled?->label);
        $this->assertSame(self::ULID, $unlabelled?->copy);
    }

    public function test_copy_using_builds_the_copied_text(): void
    {
        $reference = RecordReference::make('ulid')
            ->copyUsing(fn (string $value, ?string $label): string => "{$label}#{$value}")
            ->resolve($this->order(), 'Order');

        $this->assertSame('Order#'.self::ULID, $reference?->copy);
    }

    public function test_no_attribute_uses_the_route_key(): void
    {
        $reference = RecordReference::make()->label(false)->resolve($this->order());

        $this->assertSame(self::ULID, $reference?->copy);
    }

    public function test_characters_never_drop_below_one(): void
    {
        $this->assertSame('W', RecordReference::make('ulid')->last(0)->resolve($this->order())?->visible);
    }

    public function test_the_model_label_is_translated_and_overridable(): void
    {
        $this->assertSame('Referenced Order', ReferencedOrderResource::getModelLabel());
        $this->assertSame('Purchase', CustomReferenceOrderResource::getModelLabel());

        app('translator')->addLines(['*.Referenced Order' => 'Pedido', '*.Purchase' => 'Compra'], 'es', '*');
        app()->setLocale('es');

        $this->assertSame('Pedido', ReferencedOrderResource::getModelLabel());
        $this->assertSame('Compra', CustomReferenceOrderResource::getModelLabel());
        $this->assertSame('Pedido', ReferencedOrderResource::getRecordReference($this->order())?->label);
    }

    public function test_an_explicit_label_is_translated(): void
    {
        app('translator')->addLines(['*.Order' => 'Pedido'], 'es', '*');
        app()->setLocale('es');

        $reference = RecordReference::make('ulid')->label('Order')->resolve($this->order());

        $this->assertSame('Pedido', $reference?->label);
        $this->assertSame('Pedido: '.self::ULID, $reference?->copy);
    }

    public function test_a_backed_enum_is_referenced_by_its_value(): void
    {
        $order = $this->order();
        $order->setRawAttributes(['ulid' => ReferenceChannel::Web], true);

        $this->assertSame('web', RecordReference::make('ulid')->full()->resolve($order)?->visible);
    }

    public function test_structured_values_are_no_reference(): void
    {
        $order = $this->order();

        foreach ([collect(['a']), new ReferencedOrder(['code' => 'x']), true, ['a']] as $raw) {
            $order->setRawAttributes(['ulid' => $raw], true);

            $this->assertNull(RecordReference::make('ulid')->resolve($order), get_debug_type($raw));
        }
    }

    public function test_the_record_modal_endpoint_returns_the_reference_for_view_and_edit(): void
    {
        $order = $this->order();
        $token = Table::make(ReferencedOrder::query())
            ->recordModals(ReferencedOrderResource::class)
            ->toData()
            ->recordModals
            ->token;

        foreach (['view', 'edit'] as $mode) {
            $this->postJson(route('kinetix.tables.record.resolve'), [
                'token' => $token,
                'mode'  => $mode,
                'id'    => $order->getKey(),
            ])
                ->assertOk()
                ->assertJsonPath('reference.visible', '6PZW')
                ->assertJsonPath('reference.copy', 'Referenced Order: '.self::ULID);
        }
    }

    public function test_the_record_modal_endpoint_returns_no_reference_when_none_is_declared(): void
    {
        $order = $this->order();
        $token = Table::make(ReferencedOrder::query())
            ->recordModals(UnreferencedOrderResource::class)
            ->toData()
            ->recordModals
            ->token;

        $this->postJson(route('kinetix.tables.record.resolve'), [
            'token' => $token,
            'mode'  => 'edit',
            'id'    => $order->getKey(),
        ])
            ->assertOk()
            ->assertJsonPath('reference', null);
    }
}
