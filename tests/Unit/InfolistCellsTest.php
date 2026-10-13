<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Infolists\Components\Fieldset;
use Happones\Kinetix\Infolists\Components\Grid;
use Happones\Kinetix\Infolists\Components\Section;
use Happones\Kinetix\Infolists\Components\Tab;
use Happones\Kinetix\Infolists\Components\Tabs;
use Happones\Kinetix\Infolists\Components\TextEntry;
use Happones\Kinetix\Infolists\Infolist;
use Happones\Kinetix\Tests\TestCase;

/**
 * `Section::columns(2)` laid nothing out: every entry defaulted to the full
 * row, so the two columns asked for drew as one.
 */
class InfolistCellsTest extends TestCase
{
    /**
     * @param  array<int, mixed> $schema
     * @return array<int, mixed>
     */
    private function spans(array $schema): array
    {
        return array_map(fn (array $node): mixed => $node['columnSpan'], $schema);
    }

    public function test_a_narrow_container_gives_each_unspanned_entry_one_cell(): void
    {
        $schema = Infolist::make()->schema([
            Section::make('Two')->columns(2)->schema([TextEntry::make('a'), TextEntry::make('b'), TextEntry::make('c')->columnSpan(2)]),
            Fieldset::make('Responsive')->columns(['default' => 1, 'md' => 3])->schema([TextEntry::make('a')]),
            Grid::make(4)->schema([TextEntry::make('a')]),
            Tabs::make()->tabs([Tab::make('Tab')->columns(2)->schema([TextEntry::make('a')])]),
        ])->toArray()['schema'];

        $this->assertSame([1, 1, 2], $this->spans($schema[0]['schema']));
        $this->assertSame([1], $this->spans($schema[1]['schema']));
        $this->assertSame([1], $this->spans($schema[2]['schema']));
        $this->assertSame([1], $this->spans($schema[3]['tabs'][0]['schema'] ?? $schema[3]['schema'][0]['schema']));
    }

    public function test_the_twelve_column_grid_keeps_the_full_row_for_an_unspanned_entry(): void
    {
        $schema = Infolist::make()->schema([
            Section::make('Default')->schema([TextEntry::make('a')]),
            Section::make('Spans')->columns(12)->schema([TextEntry::make('a')->columnSpan(6), TextEntry::make('b')]),
        ])->toArray()['schema'];

        $this->assertSame(['full'], $this->spans($schema[0]['schema']));
        $this->assertSame([6, 'full'], $this->spans($schema[1]['schema']));
    }

    public function test_an_infolist_with_columns_puts_its_sections_side_by_side(): void
    {
        $schema = Infolist::make()->columns(2)->schema([
            Section::make('Left')->schema([TextEntry::make('a')]),
            Section::make('Right')->schema([TextEntry::make('b')]),
        ])->toArray()['schema'];

        $this->assertSame([1, 1], $this->spans($schema));
        $this->assertSame(['full'], $this->spans(Infolist::make()->schema([Section::make('Only')])->toArray()['schema']));
    }
}
