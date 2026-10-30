<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Exports\ExportColumn;
use Happones\Kinetix\Exports\Exporter;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ExpCategory extends Model
{
    protected $table = 'exp_categories';

    protected $guarded = [];

    public $timestamps = false;

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}

class ExpTag extends Model
{
    protected $table = 'exp_tags';

    protected $guarded = [];

    public $timestamps = false;
}

class ExpProduct extends Model
{
    protected $table = 'exp_products';

    protected $guarded = [];

    public $timestamps = false;

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpCategory::class, 'category_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(ExpTag::class, 'exp_product_tag', 'product_id', 'tag_id')->orderBy('exp_tags.id');
    }
}

class ExpItem extends Model
{
    protected $table = 'exp_items';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['meta' => 'array'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(ExpProduct::class, 'product_id');
    }
}

class ExpItemExporter extends Exporter
{
    protected static ?string $model = ExpItem::class;

    public static int $chunk = 20;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id'),
            ExportColumn::make('product.name'),
            ExportColumn::make('product.category.slug'),
            ExportColumn::make('product.category.parent.slug'),
            ExportColumn::make('product.tags.name'),
            ExportColumn::make('meta.color'),
        ];
    }

    public function chunkSize(): int
    {
        return static::$chunk;
    }
}

/**
 * Dot-notation export columns read relations — eager loaded per chunk, never
 * one query per record.
 */
class ExportRelationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('exp_categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('parent_id')->nullable();
            $table->string('slug');
        });
        Schema::create('exp_tags', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('exp_products', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->unsignedInteger('category_id')->nullable();
        });
        Schema::create('exp_product_tag', function (Blueprint $table): void {
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('tag_id');
        });
        Schema::create('exp_items', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('product_id')->nullable();
            $table->json('meta')->nullable();
        });

        ExpCategory::create(['id' => 1, 'slug' => 'food']);
        ExpCategory::create(['id' => 2, 'slug' => 'drinks', 'parent_id' => 1]);
        ExpTag::create(['id' => 1, 'name' => 'Oferta']);
        ExpTag::create(['id' => 2, 'name' => 'Nuevo']);
        ExpProduct::create(['id' => 1, 'name' => 'Agua', 'category_id' => 2]);
        ExpProduct::create(['id' => 2, 'name' => 'Pan', 'category_id' => null]);
        DB::table('exp_product_tag')->insert([['product_id' => 1, 'tag_id' => 1], ['product_id' => 1, 'tag_id' => 2]]);

        for ($i = 1; $i <= 45; $i++) {
            ExpItem::create(['product_id' => $i % 2 === 1 ? 1 : 2, 'meta' => ['color' => "c{$i}"]]);
        }

        ExpItemExporter::$chunk = 20;
    }

    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);

        parent::tearDown();
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function rows(Exporter $exporter): array
    {
        $rows = [];

        $exporter->eachChunk(function ($records) use ($exporter, &$rows): void {
            foreach ($records as $record) {
                $rows[] = $exporter->mapRecord($record);
            }
        });

        return $rows;
    }

    public function test_columns_derive_their_eager_loads(): void
    {
        $this->assertSame(['product', 'product.category', 'product.category.parent', 'product.tags'], (new ExpItemExporter)->eagerLoads());
    }

    public function test_relation_paths_export_with_nothing_lazy_loaded(): void
    {
        Model::preventLazyLoading();

        $rows = $this->rows(new ExpItemExporter);

        $this->assertCount(45, $rows);
        $this->assertSame([1, 'Agua', 'drinks', 'food', 'Oferta, Nuevo', 'c1'], $rows[0]);
        $this->assertSame([2, 'Pan', null, null, '', 'c2'], $rows[1]);
    }

    public function test_each_chunk_costs_a_fixed_number_of_queries(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->rows(new ExpItemExporter);

        // 3 chunks × (items + product + category + parent + tags) = 15, plus
        // the empty page that ends the key-paged loop: never one per record.
        $this->assertLessThanOrEqual(16, $queries);
    }

    public function test_an_explicit_order_is_kept(): void
    {
        $exporter = new class extends ExpItemExporter
        {
            public function query(): Builder
            {
                return ExpItem::query()->orderByDesc('id');
            }
        };

        $this->assertSame([45, 44, 43], array_slice(array_column($this->rows($exporter), 0), 0, 3));
    }

    public function test_without_an_order_it_pages_by_key(): void
    {
        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });

        $this->rows(new ExpItemExporter);

        $pages = array_values(array_filter($sql, fn (string $q): bool => str_contains($q, 'from "exp_items"')));
        $this->assertStringContainsString('"exp_items"."id" >', $pages[1]);
        $this->assertStringNotContainsString('offset', $pages[1]);
    }

    public function test_a_list_can_use_its_own_separator_and_drop_repeats(): void
    {
        $exporter = new class extends ExpItemExporter
        {
            public static function getColumns(): array
            {
                return [
                    ExportColumn::make('product.tags.name')->separator(' | '),
                    ExportColumn::make('product.tags.*.name')->distinct(),
                ];
            }
        };

        DB::table('exp_product_tag')->insert(['product_id' => 1, 'tag_id' => 1]);

        $this->assertSame(['Oferta | Oferta | Nuevo', 'Oferta, Nuevo'], $this->rows($exporter)[0]);
    }

    public function test_extra_eager_loads_come_from_with(): void
    {
        $exporter = new class extends ExpItemExporter
        {
            public static function getColumns(): array
            {
                return [ExportColumn::make('id')->formatStateUsing(fn ($id, ExpItem $item) => $item->product?->name)];
            }

            public function with(): array
            {
                return ['product'];
            }
        };

        Model::preventLazyLoading();

        $this->assertSame(['Agua'], $this->rows($exporter)[0]);
    }
}
