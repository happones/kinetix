<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Forms\Components\NumberField;
use Happones\Kinetix\Forms\Components\Select;
use Happones\Kinetix\Tables\Filters\FormFilter;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class FormFilterItem extends Model
{
    protected $table = 'form_filter_items';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * A FormFilter gathers several inputs and hands them to the query callback as
 * an array — the Filament `->form([...])->query(fn ($q, array $data))` pattern.
 */
class FormFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('form_filter_items', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->integer('price');
            $table->string('category');
        });

        FormFilterItem::insert([
            ['name' => 'A', 'price' => 10, 'category' => 'books'],
            ['name' => 'B', 'price' => 50, 'category' => 'books'],
            ['name' => 'C', 'price' => 90, 'category' => 'toys'],
        ]);
    }

    private function priceFilter(): FormFilter
    {
        return FormFilter::make('price')
            ->schema([
                NumberField::make('from'),
                NumberField::make('to'),
                Select::make('category')->options(['books' => 'Books', 'toys' => 'Toys']),
            ])
            ->query(function (Builder $query, array $data): void {
                $query
                    ->when($data['from'] ?? null, fn ($q, $v) => $q->where('price', '>=', $v))
                    ->when($data['to'] ?? null, fn ($q, $v) => $q->where('price', '<=', $v))
                    ->when($data['category'] ?? null, fn ($q, $v) => $q->where('category', $v));
            });
    }

    public function test_it_applies_a_composite_query_from_the_data_array(): void
    {
        $query = FormFilterItem::query();
        $this->priceFilter()->apply($query, ['from' => 20, 'to' => 80, 'category' => 'books']);

        $this->assertSame(['B'], $query->pluck('name')->all());
    }

    public function test_blank_sub_values_are_ignored(): void
    {
        // Only `from` set — `to` and `category` are blank and must not filter.
        $query = FormFilterItem::query();
        $this->priceFilter()->apply($query, ['from' => 20, 'to' => '', 'category' => null]);

        $this->assertSame(['B', 'C'], $query->orderBy('name')->pluck('name')->all());
    }

    public function test_an_empty_value_is_a_no_op(): void
    {
        $query = FormFilterItem::query();
        $this->priceFilter()->apply($query, []);

        $this->assertSame(['A', 'B', 'C'], $query->orderBy('name')->pluck('name')->all());
    }

    public function test_it_serializes_its_schema(): void
    {
        $data = $this->priceFilter()->toData();

        $this->assertSame('form', $data->type);
        $this->assertIsArray($data->schema);
        $this->assertSame(['from', 'to', 'category'], array_column($data->schema, 'name'));
    }
}
