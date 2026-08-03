<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Filters\SelectFilter;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class IsolationPost extends Model
{
    protected $table = 'isolation_posts';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * The table applies the request's search/sort/filters to a builder of its OWN.
 *
 * It used to apply them to the very instance the developer passed in, which
 * leaked the table's filters back into their variable and double-applied them if
 * the table was rendered twice.
 */
class TableQueryIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('isolation_posts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->string('status')->default('draft');
        });

        IsolationPost::create(['title' => 'A', 'status' => 'draft']);
        IsolationPost::create(['title' => 'B', 'status' => 'draft']);
        IsolationPost::create(['title' => 'C', 'status' => 'published']);
    }

    private function table(Builder|IsolationPost|string $queryOrModel): Table
    {
        return Table::make($queryOrModel)
            ->columns([TextColumn::make('title')->searchable()])
            ->filters([SelectFilter::make('status')->options([
                'draft'     => 'Draft',
                'published' => 'Published',
            ])])
            ->paginated(false);
    }

    public function test_rendering_does_not_leak_the_filters_into_the_callers_query(): void
    {
        request()->merge(['filters' => ['status' => 'published']]);

        $query = IsolationPost::query();
        $table = $this->table($query);

        $this->assertCount(1, $table->toData()->records);

        // The developer's own builder must be untouched: reusing it after
        // rendering the table used to silently inherit the table's filters.
        $this->assertSame(3, $query->count());
    }

    public function test_rendering_does_not_leak_the_search_into_the_callers_query(): void
    {
        request()->merge(['search' => 'A']);

        $query = IsolationPost::query();
        $table = $this->table($query);

        $this->assertCount(1, $table->toData()->records);
        $this->assertSame(3, $query->count());
    }

    public function test_rendering_twice_does_not_accumulate_clauses(): void
    {
        request()->merge(['filters' => ['status' => 'draft'], 'search' => 'A']);

        $query = IsolationPost::query();
        $table = $this->table($query);

        $table->toData();
        $table->toData();

        // Re-applying the same predicates happened to yield the same rows, which
        // is why this stayed invisible — but the clauses did pile up on the
        // caller's builder. Assert the builder itself, not just the result.
        $this->assertSame([], $query->getQuery()->wheres);
        $this->assertSame([], $query->getEagerLoads());
    }

    public function test_rendering_twice_yields_the_same_records(): void
    {
        request()->merge(['filters' => ['status' => 'draft']]);

        $table = $this->table(IsolationPost::query());

        $this->assertCount(2, $table->toData()->records);
        $this->assertCount(2, $table->toData()->records);
    }

    public function test_a_caller_query_with_its_own_constraints_still_applies(): void
    {
        $table = $this->table(IsolationPost::query()->where('status', 'draft'));

        // Cloning must preserve the constraints the developer put on the query.
        $this->assertCount(2, $table->toData()->records);
    }

    public function test_the_callers_constraints_survive_the_tables_filters(): void
    {
        request()->merge(['filters' => ['status' => 'published']]);

        // The base query excludes published rows, so the filter can only narrow
        // further — never widen past the developer's boundary.
        $table = $this->table(IsolationPost::query()->where('status', 'draft'));

        $this->assertCount(0, $table->toData()->records);
    }

    public function test_a_model_class_or_instance_is_accepted_as_before(): void
    {
        $this->assertCount(3, $this->table(IsolationPost::class)->toData()->records);
        $this->assertCount(3, $this->table(new IsolationPost)->toData()->records);
    }

    public function test_an_invalid_source_still_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        /** @phpstan-ignore-next-line intentionally invalid input */
        Table::make(42)->columns([TextColumn::make('title')])->toData();
    }
}
