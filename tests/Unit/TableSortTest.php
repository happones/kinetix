<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Schema;

class SortAuthor extends Model
{
    protected $table = 'sort_authors';

    public $timestamps = false;

    protected $guarded = [];
}

class SortPost extends Model
{
    protected $table = 'sort_posts';

    public $timestamps = false;

    protected $guarded = [];

    public function author(): BelongsTo
    {
        return $this->belongsTo(SortAuthor::class, 'author_id');
    }
}

class TableSortTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('sort_authors', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('sort_posts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->unsignedInteger('author_id');
        });

        $zed  = SortAuthor::create(['name' => 'Zed']);
        $anna = SortAuthor::create(['name' => 'Anna']);

        SortPost::create(['title' => 'By Zed', 'author_id' => $zed->id]);
        SortPost::create(['title' => 'By Anna', 'author_id' => $anna->id]);
    }

    /**
     * @return array<int, string>
     */
    private function titlesFor(array $query): array
    {
        Request::merge($query);

        $data = Table::make(SortPost::query())
            ->columns([
                TextColumn::make('title')->sortable(),
                TextColumn::make('author.name')->sortable(),
            ])
            ->toArray();

        return array_map(fn ($row) => $row['values']['title'], $data['records']);
    }

    public function test_sorts_ascending_by_related_column(): void
    {
        $this->assertSame(
            ['By Anna', 'By Zed'],
            $this->titlesFor(['sort' => 'author.name', 'direction' => 'asc'])
        );
    }

    public function test_sorts_descending_by_related_column(): void
    {
        $this->assertSame(
            ['By Zed', 'By Anna'],
            $this->titlesFor(['sort' => 'author.name', 'direction' => 'desc'])
        );
    }

    public function test_ignores_sort_by_a_non_sortable_or_unknown_column(): void
    {
        // `title` here is NOT marked sortable, and `bogus` is not a column at
        // all — neither should reach orderBy. Order stays insertion order.
        Request::merge(['sort' => 'bogus', 'direction' => 'asc']);

        $data = Table::make(SortPost::query())
            ->columns([
                TextColumn::make('title'), // not sortable
                TextColumn::make('author.name')->sortable(),
            ])
            ->toArray();

        $titles = array_map(fn ($row) => $row['values']['title'], $data['records']);
        $this->assertSame(['By Zed', 'By Anna'], $titles);
    }

    public function test_custom_sort_closure_wins(): void
    {
        Request::merge(['sort' => 'title', 'direction' => 'asc']);

        $data = Table::make(SortPost::query())
            ->columns([
                TextColumn::make('title')->sortable(
                    using: fn ($query, $direction) => $query->orderBy('id', 'desc')
                ),
            ])
            ->toArray();

        // Custom resolver forces id DESC regardless of the requested column.
        $titles = array_map(fn ($row) => $row['values']['title'], $data['records']);
        $this->assertSame(['By Anna', 'By Zed'], $titles);
    }
}
