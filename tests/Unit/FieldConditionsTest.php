<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Forms\Components\FieldCondition;
use Happones\Kinetix\Forms\Components\Repeater;
use Happones\Kinetix\Forms\Components\Select;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Client-side conditional rules (visibleWhen/hiddenWhen/requiredWhen/
 * disabledWhen) serialize to the browser AND are mirrored into server-side
 * validation + state, so a conditionally hidden field is never required and
 * never persisted, and a conditionally required one is enforced on submit.
 */
class FieldConditionsTest extends TestCase
{
    private function conditionalForm(): Form
    {
        return Form::make()->schema([
            Select::make('type')->options(['person' => 'Person', 'company' => 'Company']),
            TextInput::make('company_name')->visibleWhen('type', 'company'),
            TextInput::make('reason')->requiredWhen('type', 'company'),
        ]);
    }

    public function test_conditions_are_serialized_on_the_field(): void
    {
        $schema = $this->conditionalForm()->toArray()['schema'];

        $byName = [];
        foreach ($schema as $field) {
            $byName[$field['name']] = $field;
        }

        $this->assertNull($byName['type']['conditions'] ?? null);
        $this->assertSame(
            ['field' => 'type', 'operator' => 'equals', 'value' => 'company'],
            $byName['company_name']['conditions']['visible'],
        );
        $this->assertSame(
            ['field' => 'type', 'operator' => 'equals', 'value' => 'company'],
            $byName['reason']['conditions']['required'],
        );
    }

    public function test_a_conditionally_hidden_field_is_excluded_from_rules(): void
    {
        $form = $this->conditionalForm();

        // type !== 'company' → company_name is hidden, so it has no rules.
        $rules = $form->getValidationRules(['type' => 'person']);
        $this->assertArrayNotHasKey('company_name', $rules);

        // type === 'company' → company_name is shown again.
        $rules = $form->getValidationRules(['type' => 'company']);
        $this->assertArrayHasKey('company_name', $rules);
    }

    public function test_a_conditionally_required_field_gains_required(): void
    {
        $form = $this->conditionalForm();

        // Not required while the condition fails…
        $rules = $form->getValidationRules(['type' => 'person']);
        $this->assertNotContains('required', $rules['reason'] ?? []);

        // …required once it holds.
        $rules = $form->getValidationRules(['type' => 'company']);
        $this->assertContains('required', $rules['reason']);
    }

    public function test_a_conditionally_hidden_value_is_not_persisted(): void
    {
        $form = $this->conditionalForm();

        $state = $form->getState([
            'type'         => 'person',
            'company_name' => 'Smuggled Inc', // field is hidden for 'person'
        ]);

        $this->assertArrayNotHasKey('company_name', $state);
        $this->assertSame('person', $state['type']);
    }

    public function test_field_condition_operators(): void
    {
        $this->assertTrue((new FieldCondition('t', FieldCondition::EQUALS, 'a'))->passes(['t' => 'a']));
        $this->assertFalse((new FieldCondition('t', FieldCondition::EQUALS, 'a'))->passes(['t' => 'b']));

        $this->assertTrue((new FieldCondition('t', FieldCondition::IN, ['a', 'b']))->passes(['t' => 'b']));
        $this->assertFalse((new FieldCondition('t', FieldCondition::NOT_IN, ['a', 'b']))->passes(['t' => 'b']));

        $this->assertTrue((new FieldCondition('t', FieldCondition::TRUTHY))->passes(['t' => 1]));
        $this->assertTrue((new FieldCondition('t', FieldCondition::FALSY))->passes(['t' => 0]));

        $this->assertTrue((new FieldCondition('t', FieldCondition::FILLED))->passes(['t' => 'x']));
        $this->assertTrue((new FieldCondition('t', FieldCondition::BLANK))->passes(['t' => '']));
        $this->assertTrue((new FieldCondition('t', FieldCondition::BLANK))->passes([]));
    }

    /**
     * The browser and the server evaluate the same conditions, and must agree
     * — a field the browser shows but the server takes for hidden is dropped
     * on save, and the reverse blocks the submit on a field nobody can see.
     * Every case in the shared fixture is also run by
     * `useKinetixFieldConditions.spec.ts` against the JS evaluator.
     *
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function sharedConditionCases(): iterable
    {
        $cases = json_decode((string) file_get_contents(__DIR__.'/../js/fixtures/field-conditions.json'), true);

        foreach ($cases as $index => $case) {
            yield "#{$index} {$case['operator']}" => [$case];
        }
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('sharedConditionCases')]
    public function test_conditions_evaluate_as_the_shared_fixture_says(array $case): void
    {
        $data = ($case['missing'] ?? false) ? [] : ['other' => $case['actual']];

        $this->assertSame(
            $case['expected'],
            (new FieldCondition('other', $case['operator'], $case['value']))->passes($data),
            json_encode($case),
        );
    }

    public function test_an_unknown_operator_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FieldCondition('type', 'equal', 'company');
    }

    public function test_in_needs_a_list(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FieldCondition('country', FieldCondition::IN, 'MX');
    }

    /**
     * A repeater item's fields show and hide against THAT item's values in the
     * browser; the server stored a hidden field's value with the item anyway.
     */
    public function test_a_repeater_item_drops_the_values_its_conditions_hide(): void
    {
        $form = Form::make()->schema([
            Repeater::make('contacts')->schema([
                Select::make('type')->options(['person' => 'Person', 'company' => 'Company']),
                TextInput::make('company')->visibleWhen('type', 'company'),
            ]),
        ]);

        $state = $form->getState(['contacts' => [
            ['type' => 'person', 'company' => 'Leftover', 'uuid' => 'a1'],
            ['type' => 'company', 'company' => 'Acme'],
        ]]);

        $this->assertSame([
            ['type' => 'person', 'uuid' => 'a1'],
            ['type' => 'company', 'company' => 'Acme'],
        ], $state['contacts']);
    }
}
