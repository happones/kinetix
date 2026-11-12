<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SwItem extends Model
{
    protected $table = 'sw_items';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * simplePaginatedWhileSearching(): a search request pages without COUNT(*);
 * the same table without a search still reports its total.
 */
class TableSimpleWhileSearchingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('sw_items', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
        });

        foreach (range(1, 25) as $i) {
            SwItem::create(['title' => "Item {$i}"]);
        }
    }

    /**
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function render(bool $enabled = true): array
    {
        $table = Table::make(SwItem::query())->columns([TextColumn::make('title')->searchable()]);

        if ($enabled) {
            $table->simplePaginatedWhileSearching();
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $data = $table->toArray();

        $counts = collect(DB::getQueryLog())
            ->filter(fn (array $q): bool => str_contains(strtolower((string) $q['query']), 'count(*)'))
            ->count();

        DB::disableQueryLog();

        return [$data, $counts];
    }

    public function test_a_search_pages_without_a_count(): void
    {
        request()->merge(['search' => 'Item 1']);

        [$data, $counts] = $this->render();

        $this->assertSame(0, $counts);
        $this->assertNull($data['pagination']['total']);
        $this->assertTrue($data['pagination']['hasMore']);
    }

    public function test_without_a_search_the_table_still_counts(): void
    {
        [$data, $counts] = $this->render();

        $this->assertSame(1, $counts);
        $this->assertSame(25, $data['pagination']['total']);
    }

    public function test_a_blank_search_is_not_a_search(): void
    {
        request()->merge(['search' => '  ']);

        [, $counts] = $this->render();

        $this->assertSame(1, $counts);
    }

    public function test_it_is_off_by_default(): void
    {
        request()->merge(['search' => 'Item 1']);

        [$data, $counts] = $this->render(enabled: false);

        $this->assertSame(1, $counts);
        $this->assertNotNull($data['pagination']['total']);
    }
}
