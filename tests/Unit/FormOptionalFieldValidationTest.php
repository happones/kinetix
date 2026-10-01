<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Forms\Components\BusinessHours;
use Happones\Kinetix\Forms\Components\NumberField;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * An optional field must accept being left empty: Laravel's
 * ConvertEmptyStringsToNull hands the validator `null` for an emptied input,
 * which format rules (`email`, `url`, `numeric`, `min:`) reject unless the
 * field is also `nullable`.
 */
class FormOptionalFieldValidationTest extends TestCase
{
    private function optionalForm(): Form
    {
        return Form::make()->schema([
            TextInput::make('website')->url(),
            TextInput::make('email')->email(),
            TextInput::make('nickname')->minLength(3),
            NumberField::make('age')->numeric(),
            BusinessHours::make('hours'),
        ]);
    }

    public function test_optional_fields_with_rules_are_nullable(): void
    {
        $this->assertSame(
            [
                'website'  => ['nullable', 'url'],
                'email'    => ['nullable', 'email'],
                'nickname' => ['nullable', 'min:3'],
                'age'      => ['nullable', 'numeric'],
                'hours'    => ['nullable', 'array', 'kinetix_weekly_schedule'],
            ],
            $this->optionalForm()->getValidationRules(),
        );
    }

    public function test_optional_fields_accept_null_and_empty_values(): void
    {
        $empty = ['website' => null, 'email' => null, 'nickname' => null, 'age' => null, 'hours' => null];

        $this->assertFalse($this->optionalForm()->makeValidator($empty)->fails());
        $this->assertFalse($this->optionalForm()->makeValidator(array_map(fn () => '', $empty))->fails());
    }

    public function test_optional_fields_still_validate_a_filled_value(): void
    {
        $validator = $this->optionalForm()->makeValidator([
            'website'  => 'not a url',
            'email'    => 'not-an-email',
            'nickname' => 'ab',
            'age'      => 'abc',
        ]);

        $this->assertSame(
            ['website', 'email', 'nickname', 'age'],
            array_keys($validator->errors()->messages()),
        );
    }

    public function test_required_fields_are_not_made_nullable(): void
    {
        $form = Form::make()->schema([TextInput::make('email')->required()->email()]);

        $this->assertSame(['email' => ['required', 'email']], $form->getValidationRules());
        $this->assertTrue($form->makeValidator(['email' => null])->fails());
    }

    public function test_conditional_required_is_nullable_only_while_not_required(): void
    {
        $optional = Form::make()->schema([TextInput::make('vat')->required(fn () => false)->maxLength(20)]);
        $required = Form::make()->schema([TextInput::make('vat')->required(fn () => true)->maxLength(20)]);

        $this->assertSame(['vat' => ['nullable', 'max:20']], $optional->getValidationRules());
        $this->assertSame(['vat' => ['required', 'max:20']], $required->getValidationRules());
    }

    public function test_presence_rules_still_run_alongside_nullable(): void
    {
        $form = Form::make()->schema([
            TextInput::make('reason')->rules(['required_if:status,rejected', 'string']),
        ]);

        $this->assertTrue($form->makeValidator(['status' => 'rejected', 'reason' => null])->fails());
        $this->assertFalse($form->makeValidator(['status' => 'approved', 'reason' => null])->fails());
    }

    public function test_an_explicit_nullable_is_not_duplicated_and_rule_less_fields_stay_rule_less(): void
    {
        $form = Form::make()->schema([
            TextInput::make('website')->rules(['nullable'])->url(),
            TextInput::make('notes'),
        ]);

        $this->assertSame(['website' => ['nullable', 'url'], 'notes' => []], $form->getValidationRules());
    }

    public function test_rule_objects_reach_the_validator_untouched(): void
    {
        $form = Form::make()->schema([
            TextInput::make('password')->required()->rules(['string', Password::min(8)]),
        ]);

        $this->assertInstanceOf(Password::class, $form->getValidationRules()['password'][2]);
        $this->assertTrue($form->makeValidator(['password' => 'short'])->fails());
        $this->assertFalse($form->makeValidator(['password' => 'long-enough'])->fails());
    }

    public function test_the_client_payload_carries_rule_strings_only(): void
    {
        $form = Form::make()->schema([
            TextInput::make('password')->required()->rules([Password::min(8)]),
            TextInput::make('status')->rules([Rule::in(['draft', 'published'])]),
        ]);

        $this->assertSame(
            ['password' => ['required'], 'status' => ['nullable', 'in:"draft","published"']],
            $form->toArray()['rules'],
        );
    }
}
