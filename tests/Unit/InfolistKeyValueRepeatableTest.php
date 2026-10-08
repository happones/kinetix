<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use ArrayObject;
use Happones\Kinetix\Infolists\Components\KeyValueEntry;
use Happones\Kinetix\Infolists\Components\RepeatableEntry;
use Happones\Kinetix\Infolists\Components\TextEntry;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class KvOrder extends Model
{
    protected $table = 'kv_orders';

    public $timestamps = false;

    protected $guarded = [];

    public function lines(): HasMany
    {
        return $this->hasMany(KvLine::class, 'order_id');
    }
}

class KvLine extends Model
{
    protected $table = 'kv_lines';

    public $timestamps = false;

    protected $guarded = [];

    public function product(): BelongsTo
    {
        return $this->belongsTo(KvProduct::class, 'product_id');
    }
}

class KvProduct extends Model
{
    protected $table = 'kv_products';

    public $timestamps = false;

    protected $guarded = [];
}

class KvRecord extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['meta' => 'array', 'lines' => 'array'];
}

/**
 * KeyValueEntry and RepeatableEntry — the read-only infolist counterparts of
 * the KeyValue and Repeater form fields.
 */
class InfolistKeyValueRepeatableTest extends TestCase
{
    public function test_key_value_entry_serializes_pairs_and_labels(): void
    {
        $record = (new KvRecord)->forceFill([
            'meta' => ['color' => 'red', 'size' => 'L', 'tags' => ['a', 'b']],
        ]);

        $data = KeyValueEntry::make('meta')
            ->keyLabel('Property')
            ->valueLabel('Value')
            ->toData('view', $record);

        $this->assertSame('key-value', $data->type);
        $this->assertSame('Property', $data->keyLabel);
        $this->assertSame('Value', $data->valueLabel);
        // Scalars pass through; nested values are JSON-encoded.
        $this->assertSame('red', $data->state['color']);
        $this->assertSame('["a","b"]', $data->state['tags']);
    }

    public function test_repeatable_entry_serializes_one_block_per_item(): void
    {
        $record = (new KvRecord)->forceFill([
            'lines' => [
                ['name' => 'Widget', 'qty' => 2],
                ['name' => 'Gadget', 'qty' => 5],
            ],
        ]);

        $data = RepeatableEntry::make('lines')
            ->schema([
                TextEntry::make('name'),
                TextEntry::make('qty'),
            ])
            ->grid(2)
            ->toData('view', $record);

        $this->assertSame('repeatable', $data->type);
        $this->assertSame(2, $data->gridColumns);
        $this->assertCount(2, $data->repeatableItems);

        // First item's entries resolved against the array row.
        $first = $data->repeatableItems[0];
        $this->assertSame('name', $first[0]['name']);
        $this->assertSame('Widget', $first[0]['state']);
        $this->assertSame('qty', $first[1]['name']);
        $this->assertSame(2, $first[1]['state']);
    }

    public function test_repeatable_entry_handles_an_empty_set(): void
    {
        $record = (new KvRecord)->forceFill(['lines' => []]);

        $data = RepeatableEntry::make('lines')
            ->schema([TextEntry::make('name')])
            ->toData('view', $record);

        $this->assertSame([], $data->repeatableItems);
    }

    /**
     * Each array row is wrapped in a short-lived model. The raw-state memo was
     * keyed by spl_object_id(), which PHP reuses once an object is freed, so
     * later rows read an earlier row's value.
     */
    public function test_every_array_row_resolves_its_own_values(): void
    {
        $names  = ['Widget', 'Gadget', 'Gizmo', 'Doohickey', 'Sprocket', 'Flange', 'Grommet'];
        $record = (new KvRecord)->forceFill([
            'lines' => array_map(static fn (string $name): array => ['name' => $name], $names),
        ]);

        $data = RepeatableEntry::make('lines')
            ->schema([TextEntry::make('name')])
            ->toData('view', $record);

        $this->assertSame($names, array_map(static fn (array $item): mixed => $item[0]['state'], $data->repeatableItems));
    }

    public function test_key_value_entry_reads_collections_array_objects_and_json_strings(): void
    {
        foreach ([collect(['size' => 'L']), new ArrayObject(['size' => 'L']), '{"size":"L"}'] as $value) {
            $data = KeyValueEntry::make('meta')
                ->state(static fn (): mixed => $value)
                ->toData('view', new KvRecord);

            $this->assertSame(['size' => 'L'], $data->state);
        }

        // Nothing to list: null, so the entry shows its placeholder.
        $empty = KeyValueEntry::make('meta')
            ->state(static fn (): array => [])
            ->toData('view', new KvRecord);

        $this->assertNull($empty->state);
    }

    public function test_repeated_relations_load_in_one_query_each_even_when_lazy_loading_is_prevented(): void
    {
        Schema::create('kv_orders', static function (Blueprint $table): void {
            $table->increments('id');
        });
        Schema::create('kv_products', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('kv_lines', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('order_id');
            $table->unsignedInteger('product_id');
        });

        $order = KvOrder::create();
        foreach (['Widget', 'Gadget', 'Gizmo'] as $name) {
            $order->lines()->create(['product_id' => KvProduct::create(['name' => $name])->id]);
        }

        $order = KvOrder::query()->find($order->id);
        Model::preventLazyLoading();
        DB::enableQueryLog();

        try {
            $data = RepeatableEntry::make('lines')
                ->schema([TextEntry::make('product.name')])
                ->toData('view', $order);
        } finally {
            Model::preventLazyLoading(false);
        }

        $this->assertSame(
            ['Widget', 'Gadget', 'Gizmo'],
            array_map(static fn (array $item): mixed => $item[0]['state'], $data->repeatableItems),
        );
        // One query for the lines, one for their products.
        $this->assertCount(2, DB::getQueryLog());
    }

    public function test_in_a_grid_each_entry_takes_one_cell_unless_it_sets_its_span(): void
    {
        $record = (new KvRecord)->forceFill(['lines' => [['name' => 'Widget', 'note' => 'Fragile']]]);

        $data = RepeatableEntry::make('lines')
            ->schema([
                TextEntry::make('name'),
                TextEntry::make('note')->columnSpan('full'),
            ])
            ->grid(3)
            ->toData('view', $record);

        $this->assertSame(1, $data->repeatableItems[0][0]['columnSpan']);
        $this->assertSame('full', $data->repeatableItems[0][1]['columnSpan']);

        $stacked = RepeatableEntry::make('lines')
            ->schema([TextEntry::make('name')])
            ->toData('view', $record);

        $this->assertSame('full', $stacked->repeatableItems[0][0]['columnSpan']);
    }
}
