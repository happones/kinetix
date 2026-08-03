<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Data\TableStatData;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Filters\SelectFilter;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tables\TableStat;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

class StatBook extends Model
{
    protected $table = 'stat_books';

    public $timestamps = false;

    protected $guarded = [];
}

class TableStatsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('stat_books', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->string('status')->default('available');
            $table->integer('copies')->default(1);
            $table->timestamp('due_at')->nullable();
        });

        // 6 available (12 copies), 3 on loan (30 copies), 1 archived (100 copies).
        foreach (range(1, 6) as $i) {
            StatBook::create(['title' => "A{$i}", 'status' => 'available', 'copies' => 2]);
        }
        foreach (range(1, 3) as $i) {
            StatBook::create(['title' => "L{$i}", 'status' => 'loan', 'copies' => 10, 'due_at' => now()->subDay()]);
        }
        StatBook::create(['title' => 'Z', 'status' => 'archived', 'copies' => 100]);
    }

    /**
     * @param  array<int, TableStat>                       $stats
     * @return array{0: array<int, TableStatData>, 1: int}
     */
    private function render(array $stats, array $params = []): array
    {
        if ($params !== []) {
            request()->merge($params);
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $data = Table::make(StatBook::query())
            ->columns([TextColumn::make('title')->searchable()])
            ->filters([SelectFilter::make('status')->options([
                'available' => 'Available',
                'loan'      => 'On loan',
                'archived'  => 'Archived',
            ])])
            ->stats($stats)
            ->toData();

        return [$data->stats, $queries];
    }

    /**
     * @param  array<int, TableStatData> $stats
     * @return array<string, string>
     */
    private function byLabel(array $stats): array
    {
        $out = [];

        foreach ($stats as $stat) {
            $out[$stat->label] = $stat->value;
        }

        return $out;
    }

    public function test_a_table_without_stats_ships_none(): void
    {
        [$stats, $queries] = $this->render([]);

        $this->assertSame([], $stats);
        // COUNT + page read, unchanged.
        $this->assertSame(2, $queries);
    }

    public function test_counts_sums_and_averages_are_computed(): void
    {
        [$stats] = $this->render([
            TableStat::make('Total')->count(),
            TableStat::make('Copies')->sum('copies'),
            TableStat::make('Avg copies')->avg('copies')->numeric(1),
            TableStat::make('Most copies')->max('copies'),
            TableStat::make('Fewest copies')->min('copies'),
        ]);

        $this->assertSame([
            'Total'         => '10',
            'Copies'        => '142',
            'Avg copies'    => '14.2',
            'Most copies'   => '100',
            'Fewest copies' => '2',
        ], $this->byLabel($stats));
    }

    public function test_a_condition_narrows_the_card_without_narrowing_the_others(): void
    {
        [$stats] = $this->render([
            TableStat::make('Total')->count(),
            TableStat::make('On loan')->count()->where('status', 'loan'),
            TableStat::make('Overdue')->count()->where('due_at', '<', now()),
            TableStat::make('Loaned copies')->sum('copies')->where('status', 'loan'),
            TableStat::make('Undated')->count()->whereNull('due_at'),
            TableStat::make('Dated')->count()->whereNotNull('due_at'),
        ]);

        $this->assertSame([
            'Total'         => '10',
            'On loan'       => '3',
            'Overdue'       => '3',
            'Loaned copies' => '30',
            'Undated'       => '7',
            'Dated'         => '3',
        ], $this->byLabel($stats));
    }

    public function test_conditions_chain_as_and(): void
    {
        [$stats] = $this->render([
            TableStat::make('Big loans')->count()->where('status', 'loan')->where('copies', '>=', 10),
            TableStat::make('Small loans')->count()->where('status', 'loan')->where('copies', '<', 10),
        ]);

        $this->assertSame(
            ['Big loans' => '3', 'Small loans' => '0'],
            $this->byLabel($stats),
        );
    }

    /**
     * The whole reason this feature exists rather than stacking scoped
     * summarizers: a page with many cards must not scan the table once per card.
     */
    public function test_any_number_of_cards_costs_one_extra_query(): void
    {
        [, $baseline] = $this->render([]);

        [, $withOne] = $this->render([
            TableStat::make('Total')->count(),
        ]);

        [, $withEight] = $this->render([
            TableStat::make('Total')->count(),
            TableStat::make('Available')->count()->where('status', 'available'),
            TableStat::make('On loan')->count()->where('status', 'loan'),
            TableStat::make('Archived')->count()->where('status', 'archived'),
            TableStat::make('Overdue')->count()->where('due_at', '<', now()),
            TableStat::make('Copies')->sum('copies'),
            TableStat::make('Loaned copies')->sum('copies')->where('status', 'loan'),
            TableStat::make('Avg copies')->avg('copies'),
        ]);

        $this->assertSame($baseline + 1, $withOne);
        $this->assertSame(
            $baseline + 1,
            $withEight,
            "Eight cards cost {$withEight} queries against a {$baseline}-query baseline — the aggregates are not being batched.",
        );
    }

    public function test_cards_follow_the_tables_active_filters_by_default(): void
    {
        [$stats] = $this->render(
            [
                TableStat::make('Total')->count(),
                TableStat::make('Copies')->sum('copies'),
            ],
            ['filters' => ['status' => 'loan']],
        );

        // Only the 3 loaned rows (10 copies each) are in the filtered dataset.
        $this->assertSame(
            ['Total' => '3', 'Copies' => '30'],
            $this->byLabel($stats),
        );
    }

    public function test_ignore_filters_reports_the_whole_dataset(): void
    {
        [$stats, $queries] = $this->render(
            [
                TableStat::make('Filtered')->count(),
                TableStat::make('All books')->count()->ignoreFilters(),
                TableStat::make('All copies')->sum('copies')->ignoreFilters(),
            ],
            ['filters' => ['status' => 'loan']],
        );

        $this->assertSame([
            'Filtered'   => '3',
            'All books'  => '10',
            'All copies' => '142',
        ], $this->byLabel($stats));

        // Two datasets → two aggregate queries, not one per card.
        $this->assertSame(4, $queries);
    }

    public function test_search_narrows_the_cards_too(): void
    {
        [$stats] = $this->render(
            [TableStat::make('Total')->count()],
            ['search' => 'L1'],
        );

        $this->assertSame(['Total' => '1'], $this->byLabel($stats));
    }

    public function test_cards_describe_the_whole_filtered_set_not_one_page(): void
    {
        [$stats] = $this->render(
            [TableStat::make('Total')->count()],
            ['perPage' => '5'],
        );

        // 10 rows across two pages of 5.
        $this->assertSame(['Total' => '10'], $this->byLabel($stats));
    }

    public function test_a_hidden_card_is_neither_rendered_nor_queried(): void
    {
        [$stats, $queries] = $this->render([
            TableStat::make('Shown')->count(),
            TableStat::make('Hidden')->count()->hidden(),
            TableStat::make('Invisible')->count()->visible(false),
        ]);

        $this->assertSame(['Shown' => '10'], $this->byLabel($stats));
        $this->assertSame(3, $queries);
    }

    public function test_an_unauthorized_card_is_dropped_before_it_is_computed(): void
    {
        Gate::define('viewRevenue', fn ($user = null) => false);

        [$stats] = $this->render([
            TableStat::make('Total')->count(),
            TableStat::make('Revenue')->sum('copies')->can('viewRevenue'),
        ]);

        $this->assertSame(['Total' => '10'], $this->byLabel($stats));
    }

    public function test_every_card_is_dropped_when_none_may_render(): void
    {
        [$stats, $queries] = $this->render([
            TableStat::make('Hidden')->count()->hidden(),
        ]);

        $this->assertSame([], $stats);
        // No aggregate query at all.
        $this->assertSame(2, $queries);
    }

    public function test_formatting_and_presentation_reach_the_payload(): void
    {
        [$stats] = $this->render([
            TableStat::make('Revenue')
                ->sum('copies')
                ->money('USD')
                ->icon('book')
                ->color('success')
                ->description('All time')
                ->url('/books'),
        ]);

        $card = $stats[0];

        $this->assertSame('Revenue', $card->label);
        $this->assertStringContainsString('142', $card->value);
        $this->assertSame('book', $card->icon);
        $this->assertSame('success', $card->color);
        $this->assertSame('All time', $card->description);
        $this->assertSame('/books', $card->url);
    }

    public function test_trend_presentation_reaches_the_payload(): void
    {
        [$stats] = $this->render([
            TableStat::make('Overdue')
                ->where('due_at', '<', now())
                ->descriptionIcon('trending-up')
                ->descriptionColor('danger')
                ->description('+12% vs last month')
                ->chart([3, 5, 4, 8, 9]),
        ]);

        $card = $stats[0];

        $this->assertSame('trending-up', $card->descriptionIcon);
        $this->assertSame('danger', $card->descriptionColor);
        $this->assertSame('+12% vs last month', $card->description);
        $this->assertSame([3, 5, 4, 8, 9], $card->chart);
    }

    public function test_trend_fields_default_empty(): void
    {
        [$stats] = $this->render([TableStat::make('Total')->count()]);

        $this->assertNull($stats[0]->descriptionIcon);
        $this->assertNull($stats[0]->descriptionColor);
        $this->assertSame([], $stats[0]->chart);
    }

    public function test_affixes_wrap_the_value(): void
    {
        [$stats] = $this->render([
            TableStat::make('Rate')->avg('copies')->numeric(0)->suffix(' / title'),
        ]);

        $this->assertSame(['Rate' => '14 / title'], $this->byLabel($stats));
    }

    public function test_using_produces_a_custom_value_at_the_cost_of_its_own_query(): void
    {
        [$stats, $queries] = $this->render([
            TableStat::make('Total')->count(),
            TableStat::make('Distinct statuses')->using(
                fn ($query) => $query->distinct()->count('status'),
            ),
        ]);

        $this->assertSame([
            'Total'             => '10',
            'Distinct statuses' => '3',
        ], $this->byLabel($stats));

        // baseline 2 + 1 batched + 1 for the custom card.
        $this->assertSame(4, $queries);
    }

    public function test_cards_keep_their_declared_order(): void
    {
        [$stats] = $this->render([
            TableStat::make('First')->count(),
            TableStat::make('Second')->using(fn ($query) => 'x'),
            TableStat::make('Third')->count()->where('status', 'loan'),
        ]);

        $this->assertSame(
            ['First', 'Second', 'Third'],
            array_map(static fn ($stat) => $stat->label, $stats),
        );
    }

    public function test_an_unsupported_operator_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->render([
            TableStat::make('Bad')->count()->where('status', '; drop table stat_books; --', 'loan'),
        ]);
    }

    public function test_values_travel_as_bindings(): void
    {
        // A quote in the value must not be able to break out of the aggregate.
        [$stats] = $this->render([
            TableStat::make('Quoted')->count()->where('title', "L1' or '1'='1"),
        ]);

        $this->assertSame(['Quoted' => '0'], $this->byLabel($stats));
    }
}
