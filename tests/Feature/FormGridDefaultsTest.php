<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Forms\Components\Fieldset;
use Happones\Kinetix\Forms\Components\Grid;
use Happones\Kinetix\Forms\Components\Section;
use Happones\Kinetix\Forms\Components\Select;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Tests\TestCase;

/**
 * The grid contract, which had no test before v0.128.0 and therefore changed
 * silently. Kinetix now matches Filament: a 1-column root, `Grid::make()` at 2,
 * and a field spanning 1 column by default — so a plain field is full width and
 * `Grid::make(2)` gives two columns without annotating anything.
 */
class FormGridDefaultsTest extends TestCase
{
    /**
     * @param  array<int, mixed>    $schema
     * @return array<string, mixed>
     */
    private function render(array $schema): array
    {
        return Form::make()->schema($schema)->toArray()['schema'][0];
    }

    public function test_a_field_spans_one_column_by_default(): void
    {
        $this->assertSame(1, $this->render([TextInput::make('name')])['columnSpan']);
    }

    public function test_column_span_full_still_spans_the_row(): void
    {
        $this->assertSame('full', $this->render([TextInput::make('bio')->columnSpanFull()])['columnSpan']);
    }

    public function test_an_explicit_span_is_untouched(): void
    {
        $this->assertSame(6, $this->render([TextInput::make('name')->columnSpan(6)])['columnSpan']);
    }

    public function test_grid_defaults_to_two_columns(): void
    {
        $grid = $this->render([
            Grid::make()->schema([TextInput::make('first'), TextInput::make('last')]),
        ]);

        $this->assertSame(2, $grid['columns']);

        // Two default-span fields therefore sit side by side, no annotation.
        foreach ($grid['schema'] as $field) {
            $this->assertSame(1, $field['columnSpan']);
        }
    }

    public function test_an_explicit_grid_width_is_respected(): void
    {
        $grid = $this->render([
            Grid::make(12)->schema([TextInput::make('a')->columnSpan(6)]),
        ]);

        $this->assertSame(12, $grid['columns']);
        $this->assertSame(6, $grid['schema'][0]['columnSpan']);
    }

    /**
     * Filament's sections/fieldsets/tabs/steps are single-column until told
     * otherwise — the field inside is full width without annotation.
     */
    public function test_the_wrapping_layouts_default_to_one_column(): void
    {
        foreach ([Section::class, Fieldset::class] as $layout) {
            $rendered = $this->render([
                $layout::make('Heading')->schema([TextInput::make('name')]),
            ]);

            $this->assertSame(1, $rendered['columns'], $layout.' should default to one column.');
        }
    }

    public function test_a_relationship_select_participates_in_the_grid(): void
    {
        $grid = $this->render([
            Grid::make(2)->schema([
                Select::make('author_id'),
                TextInput::make('title'),
            ]),
        ]);

        $this->assertSame(2, $grid['columns']);
        $this->assertSame(1, $grid['schema'][0]['columnSpan']);
    }

    public function test_columns_and_spans_accept_breakpoint_maps(): void
    {
        $grid = $this->render([
            Grid::make(['default' => 1, 'sm' => 2, 'xl' => 3])->schema([
                TextInput::make('street')->columnSpan(['sm' => 2]),
            ]),
        ]);

        $this->assertSame(['default' => 1, 'sm' => 2, 'xl' => 3], $grid['columns']);
        $this->assertSame(['sm' => 2], $grid['schema'][0]['columnSpan']);
    }

    public function test_section_columns_accept_breakpoint_maps(): void
    {
        $rendered = $this->render([
            Section::make('Shipping')
                ->columns(['sm' => 2, 'xl' => 3])
                ->schema([TextInput::make('street')]),
        ]);

        $this->assertSame(['sm' => 2, 'xl' => 3], $rendered['columns']);
    }
}
