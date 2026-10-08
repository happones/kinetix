<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Forms\Components\Select;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Forms\Support\Get;
use Happones\Kinetix\Forms\Support\Set;
use Happones\Kinetix\Tests\TestCase;

/**
 * Server-driven form reactivity (Phase 1 — foundations): a recompute re-evaluates
 * reactive closures ($get/$set) against the in-flight state, so dependent
 * options, visibility and disabled recompute, and `afterStateUpdated` can push
 * derived values back. No endpoint/UI yet — this exercises the engine directly.
 */
class FormReactivityTest extends TestCase
{
    private function dependentForm(): Form
    {
        return Form::make()->schema([
            Select::make('country')
                ->live()
                ->options(['es' => 'Spain', 'fr' => 'France'])
                ->afterStateUpdated(fn (Set $set) => $set('state', null)),
            Select::make('state')
                ->options(fn (Get $get) => match ($get('country')) {
                    'es'    => ['mad' => 'Madrid', 'bcn' => 'Barcelona'],
                    'fr'    => ['par' => 'Paris'],
                    default => [],
                }),
            TextInput::make('note')->visibleWhen('country', 'fr'),
        ]);
    }

    private function schemaByName(array $schema): array
    {
        $byName = [];
        foreach ($schema as $field) {
            $byName[$field['name']] = $field;
        }

        return $byName;
    }

    public function test_recompute_reevaluates_dependent_options(): void
    {
        $result = $this->dependentForm()->recompute(['country' => 'es']);
        $byName = $this->schemaByName($result['schema']);

        $this->assertSame(
            ['mad' => 'Madrid', 'bcn' => 'Barcelona'],
            $byName['state']['options'],
        );

        // A different country yields different dependent options.
        $result = $this->dependentForm()->recompute(['country' => 'fr']);
        $byName = $this->schemaByName($result['schema']);
        $this->assertSame(['par' => 'Paris'], $byName['state']['options']);
    }

    public function test_after_state_updated_pushes_changes_back(): void
    {
        // Country changed to 'fr' → afterStateUpdated clears 'state'.
        $result = $this->dependentForm()->recompute([
            'country' => 'fr',
            'state'   => 'mad', // a stale value from the previous country
        ]);

        $this->assertArrayHasKey('state', $result['changes']);
        $this->assertNull($result['changes']['state']);
    }

    public function test_get_reads_and_set_writes_state(): void
    {
        $get = new Get(['a' => 1, 'nested' => ['b' => 2]]);
        $this->assertSame(1, $get('a'));
        $this->assertSame(2, $get('nested.b'));
        $this->assertSame('fallback', $get('missing', 'fallback'));
        $this->assertSame(['a' => 1, 'nested' => ['b' => 2]], $get());

        $state = ['x' => 1];
        $set   = new Set($state);
        $set('y', 9);
        $this->assertSame(9, $state['y']);
        $this->assertSame(['y' => 9], $set->changes());
    }

    public function test_a_record_closure_still_works_unchanged(): void
    {
        // Backward compat: a legacy fn ($record) closure still receives the
        // record (not a Get), so existing forms keep working.
        $captured = 'untouched';

        $form = Form::make()->schema([
            TextInput::make('name')->disabled(function ($record) use (&$captured) {
                $captured = $record;

                return false;
            }),
        ]);

        $form->toArray();
        $this->assertNull($captured); // no record → null, not a Get instance
    }

    /**
     * A three-level cascade: country clears state, state clears city. Every
     * live field's hook used to run on every recompute, so picking a state
     * re-ran the country's hook and wiped the state just chosen.
     */
    public function test_only_the_changed_fields_hooks_run(): void
    {
        $form = Form::make()->schema([
            Select::make('country')->live()->options(['es' => 'Spain'])
                ->afterStateUpdated(fn (Set $set) => $set('state', null)),
            Select::make('state')->live()->options(['mad' => 'Madrid'])
                ->afterStateUpdated(fn (Set $set) => $set('city', null)),
            TextInput::make('city'),
        ]);

        $result = $form->recompute(['country' => 'es', 'state' => 'mad', 'city' => 'Centro'], ['state']);

        $this->assertSame(['city' => null], $result['changes']);
    }

    public function test_without_changed_names_every_live_hook_still_runs(): void
    {
        // A client from before 0.208 sends no `changed`: the old behaviour.
        $result = $this->dependentForm()->recompute(['country' => 'fr', 'state' => 'mad']);

        $this->assertArrayHasKey('state', $result['changes']);
    }

    /**
     * Get read nothing on the first render, so an edit form's dependent select
     * rendered with no options until the user touched the parent field.
     */
    public function test_the_first_render_seeds_get_with_the_form_values(): void
    {
        $schema = $this->dependentForm()->fill(['country' => 'fr'])->toArray()['schema'];

        $this->assertSame(['par' => 'Paris'], $this->schemaByName($schema)['state']['options']);
    }

    /**
     * A nested write shipped nested (`['items' => [['qty' => 5]]]`), and the
     * client's merge replaced the whole array with it.
     */
    public function test_a_nested_set_ships_by_path(): void
    {
        $state = ['items' => [['name' => 'A', 'qty' => 1], ['name' => 'B', 'qty' => 2]]];
        $set   = new Set($state);

        $set('items.0.qty', 5);

        $this->assertSame(['items.0.qty' => 5], $set->changes());
        // The working state is still updated in place for later reads.
        $this->assertSame(5, $state['items'][0]['qty']);
        $this->assertSame('B', $state['items'][1]['name']);
    }
}
