<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Forms\Components\FieldCondition;
use Happones\Kinetix\Forms\Components\Select;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Tests\TestCase;

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
}
