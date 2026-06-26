<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Tables\Columns\Summarizers\Average;
use Happones\Kinetix\Tables\Columns\Summarizers\Count;
use Happones\Kinetix\Tables\Columns\Summarizers\Range;
use Happones\Kinetix\Tables\Columns\Summarizers\Sum;
use Happones\Kinetix\Tables\Columns\Summarizers\Summarizer;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class SummaryProduct extends Model
{
    protected $table = 'summary_products';

    public $timestamps = false;

    protected $guarded = [];
}

class SummarizerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('summary_products', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->integer('price');
            $table->integer('rating')->nullable();
            $table->boolean('is_published')->default(false);
        });

        SummaryProduct::insert([
            ['name' => 'A', 'price' => 100, 'rating' => 4, 'is_published' => true],
            ['name' => 'B', 'price' => 200, 'rating' => 2, 'is_published' => true],
            ['name' => 'C', 'price' => 300, 'rating' => null, 'is_published' => false],
        ]);
    }

    private function query()
    {
        return SummaryProduct::query();
    }

    public function test_sum(): void
    {
        $this->assertSame('600', Sum::make()->summarize($this->query(), 'price')->value);
    }

    public function test_average(): void
    {
        $this->assertSame('200', Average::make()->summarize($this->query(), 'price')->value);
    }

    public function test_count_counts_rows(): void
    {
        $this->assertSame('3', Count::make()->summarize($this->query(), 'id')->value);
    }

    public function test_count_with_scoped_query(): void
    {
        $value = Count::make()
            ->query(fn ($q) => $q->where('is_published', true))
            ->summarize($this->query(), 'id')
            ->value;

        $this->assertSame('2', $value);
    }

    public function test_range_renders_min_and_max(): void
    {
        $this->assertSame('100 – 300', Range::make()->summarize($this->query(), 'price')->value);
    }

    public function test_range_excludes_null_by_default(): void
    {
        // rating has a null row; min 2, max 4.
        $this->assertSame('2 – 4', Range::make()->summarize($this->query(), 'rating')->value);
    }

    public function test_label_prefix_suffix_and_numeric(): void
    {
        $result = Sum::make()
            ->label('Total')
            ->numeric(decimalPlaces: 2, locale: 'en')
            ->prefix('$')
            ->summarize($this->query(), 'price');

        $this->assertSame('Total', $result->label);
        $this->assertSame('$600.00', $result->value);
    }

    public function test_custom_using_callback(): void
    {
        $value = Summarizer::make()
            ->using(fn ($q) => $q->max('price'))
            ->summarize($this->query(), 'price')
            ->value;

        $this->assertSame('300', $value);
    }

    public function test_hidden_summarizer_returns_null(): void
    {
        $this->assertNull(Sum::make()->hidden()->summarize($this->query(), 'price'));
        $this->assertNull(Sum::make()->visible(false)->summarize($this->query(), 'price'));
    }

    public function test_table_serializes_summaries(): void
    {
        $data = Table::make(SummaryProduct::class)
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('price')->summarize([
                    Sum::make()->label('Sum'),
                    Average::make()->label('Avg'),
                ]),
            ])
            ->toData();

        $this->assertTrue($data->hasSummaries);
        $this->assertArrayHasKey('price', $data->summaries);
        $this->assertArrayNotHasKey('name', $data->summaries);
        $this->assertCount(2, $data->summaries['price']);
        $this->assertSame('Sum', $data->summaries['price'][0]->label);
        $this->assertSame('600', $data->summaries['price'][0]->value);

        // The column flags that it carries a summary.
        $priceColumn = collect($data->columns)->firstWhere('name', 'price');
        $this->assertTrue($priceColumn->hasSummary);
    }
}
