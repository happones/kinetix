<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Forms\Components\Select;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Forms\Support\Get;
use Happones\Kinetix\Forms\Support\Set;
use Happones\Kinetix\Tests\TestCase;

/**
 * A `Form` subclass is reconstructible, so it opts into the reactivity loop:
 * its toData() seals a recompute descriptor, and the signed endpoint rebuilds
 * it to return a schema recomputed against the in-flight state.
 */
class ReactiveCountryForm extends Form
{
    protected function buildSchema(): array
    {
        return [
            Select::make('country')
                ->live()
                ->options(['es' => 'Spain', 'fr' => 'France'])
                ->afterStateUpdated(fn (Set $set) => $set('state', null)),
            Select::make('state')
                ->options(fn (Get $get) => match ($get('country')) {
                    'es'    => ['mad' => 'Madrid'],
                    'fr'    => ['par' => 'Paris'],
                    default => [],
                }),
        ];
    }
}

class FormRecomputeEndpointTest extends TestCase
{
    private function descriptor(): string
    {
        return ReactiveCountryForm::make()->toData()->recomputeDescriptor ?? '';
    }

    public function test_a_form_subclass_with_a_live_field_ships_a_recompute_descriptor(): void
    {
        $this->assertNotSame('', $this->descriptor());

        // A plain inline form with no live field ships none.
        $plain = Form::make()->schema([Select::make('x')->options(['a' => 'A'])]);
        $this->assertNull($plain->toData()->recomputeDescriptor);
    }

    public function test_the_endpoint_returns_the_recomputed_schema(): void
    {
        $response = $this->postJson(route('kinetix.forms.recompute'), [
            'descriptor' => $this->descriptor(),
            'data'       => ['country' => 'fr'],
        ]);

        $response->assertOk();

        $schema = $response->json('schema');
        $byName = [];
        foreach ($schema as $field) {
            $byName[$field['name']] = $field;
        }

        $this->assertSame(['par' => 'Paris'], $byName['state']['options']);
    }

    public function test_the_endpoint_returns_after_state_updated_changes(): void
    {
        $response = $this->postJson(route('kinetix.forms.recompute'), [
            'descriptor' => $this->descriptor(),
            'data'       => ['country' => 'fr', 'state' => 'mad'],
        ]);

        $response->assertOk();
        $this->assertNull($response->json('changes.state'));
    }

    public function test_a_tampered_descriptor_is_rejected(): void
    {
        $response = $this->postJson(route('kinetix.forms.recompute'), [
            'descriptor' => 'not-a-valid-token',
            'data'       => ['country' => 'fr'],
        ]);

        $response->assertStatus(400);
    }
}
