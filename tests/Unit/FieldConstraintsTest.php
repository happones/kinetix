<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Forms\Components\FieldCondition;
use Happones\Kinetix\Forms\Components\FileUpload;
use Happones\Kinetix\Forms\Components\NumberField;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\FieldConstraints;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Rules\Password;
use PHPUnit\Framework\Attributes\DataProvider;

class FieldConstraintsTest extends TestCase
{
    /**
     * @return array<string, array{0: list<mixed>, 1: string, 2: array<string, mixed>}>
     */
    public static function ruleFamilies(): array
    {
        return [
            'string length' => [['required', 'string', 'min:3', 'max:120'], 'text-input', ['kind' => 'string', 'required' => true, 'length' => ['min' => 3, 'max' => 120]]],
            'exact length'  => [['size:5'], 'text-input', ['kind' => 'string', 'length' => ['exact' => 5]]],
            'number range'  => [['integer', 'between:1,10', 'multiple_of:2'], 'text-input', ['kind' => 'numeric', 'range' => ['min' => 1, 'max' => 10], 'integer' => true, 'step' => 2]],
            'number bounds' => [['gt:0', 'lte:99.5'], 'number-field', ['kind' => 'numeric', 'range' => ['gt' => 0, 'max' => 99.5]]],
            'decimals'      => [['decimal:0,2'], 'text-input', ['kind' => 'numeric', 'decimals' => ['min' => 0, 'max' => 2]]],
            'digits'        => [['digits:6'], 'text-input', ['kind' => 'string', 'digits' => ['min' => 6, 'max' => 6]]],
            'array count'   => [['array', 'min:1', 'max:3', 'distinct'], 'text-input', ['kind' => 'array', 'items' => ['min' => 1, 'max' => 3], 'distinct' => true]],
            'charset'       => [['alpha_dash', 'lowercase'], 'text-input', ['kind' => 'string', 'charset' => 'alpha_dash', 'case' => 'lower']],
            'affixes'       => [['starts_with:INV-,PO-', 'doesnt_end_with:.tmp'], 'text-input', ['kind' => 'string', 'startsWith' => ['INV-', 'PO-'], 'notEndsWith' => ['.tmp']]],
            'regex'         => [['regex:/^[A-Z]{3}(,\d)?$/i'], 'text-input', ['kind' => 'string', 'pattern' => ['source' => '^[A-Z]{3}(,\d)?$', 'flags' => 'i']]],
            'format'        => [['ipv4'], 'text-input', ['kind' => 'string', 'format' => 'ipv4']],
            'not in'        => [[Rule::notIn(['admin', 'root'])], 'text-input', ['kind' => 'string', 'notIn' => ['admin', 'root']]],
            'dates'         => [['date', 'after:today', 'before_or_equal:ends_on'], 'date-picker', ['kind' => 'string', 'dates' => [['op' => 'after', 'date' => 'today'], ['op' => 'before_or_equal', 'field' => 'ends_on']]]],
            'comparisons'   => [['numeric', 'gte:min_price', 'different:old_price'], 'number-field', ['kind' => 'numeric', 'compare' => [['op' => 'gte', 'field' => 'min_price'], ['op' => 'different', 'field' => 'old_price']]]],
            'confirmed'     => [['confirmed'], 'text-input', ['kind' => 'string', 'confirmed' => true]],
            'password'      => [[Password::min(10)->mixedCase()->numbers()], 'text-input', ['kind' => 'string', 'password' => ['min' => 10, 'letters' => false, 'mixedCase' => true, 'numbers' => true, 'symbols' => false, 'uncompromised' => false]]],
            'files'         => [['mimes:png,JPG', 'max:2048', 'dimensions:min_width=800,ratio=3/2'], 'file-upload', ['kind' => 'file', 'kilobytes' => ['max' => 2048], 'fileTypes' => ['png', 'jpg'], 'dimensions' => ['minWidth' => 800, 'ratio' => 1.5]]],
            'file rule'     => [[File::types(['pdf', 'image/png'])->max(1024)], 'file-upload', ['kind' => 'file', 'fileTypes' => ['pdf'], 'mimeTypes' => ['image/png'], 'kilobytes' => ['max' => 1024]]],
            'database only' => [['unique:users,email', 'exists:teams,id'], 'text-input', ['kind' => 'string']],
        ];
    }

    /**
     * @param list<mixed>          $rules
     * @param array<string, mixed> $expected
     */
    #[DataProvider('ruleFamilies')]
    public function test_each_rule_family_becomes_a_constraint(array $rules, string $type, array $expected): void
    {
        $this->assertSame(
            $expected === ['kind' => 'string'] ? null : $expected,
            FieldConstraints::from($rules, $type),
        );
    }

    public function test_conditional_rules_become_field_conditions(): void
    {
        $this->assertEquals(
            ['required' => new FieldCondition('type', FieldCondition::EQUALS, 'company')],
            FieldConstraints::conditions(['required_if:type,company']),
        );
        $this->assertEquals(
            ['required' => new FieldCondition('plan', FieldCondition::NOT_IN, ['free', 'trial'])],
            FieldConstraints::conditions(['required_unless:plan,free,trial']),
        );
        $this->assertEquals(
            ['required' => new FieldCondition('phone', FieldCondition::BLANK)],
            FieldConstraints::conditions(['required_without:phone']),
        );
        $this->assertEquals(
            ['hidden' => new FieldCondition('is_company', FieldCondition::EQUALS, false)],
            FieldConstraints::conditions(['exclude_if:is_company,false']),
        );
        // Several fields at once stay with the server.
        $this->assertSame([], FieldConstraints::conditions(['required_with_all:a,b']));
    }

    public function test_a_field_ships_its_constraints_and_derived_conditions(): void
    {
        $schema = Form::make()->schema([
            TextInput::make('type'),
            TextInput::make('vat_id')->rules(['required_if:type,company', 'alpha_num', 'max:20']),
            TextInput::make('code')->rules(['required_if:type,company'])->requiredWhen('type', 'other'),
            NumberField::make('seats')->rules(['integer', 'min:1']),
            FileUpload::make('logo')->rules(['image', 'max:512']),
        ])->toArray()['schema'];

        $this->assertNull($schema[0]['constraints']);
        $this->assertSame(['kind' => 'string', 'length' => ['max' => 20], 'charset' => 'alpha_num'], $schema[1]['constraints']);
        $this->assertSame(['field' => 'type', 'operator' => 'equals', 'value' => 'company'], $schema[1]['conditions']['required']);
        // A condition the field sets itself wins.
        $this->assertSame('other', $schema[2]['conditions']['required']['value']);
        $this->assertSame(['kind' => 'numeric', 'range' => ['min' => 1], 'integer' => true], $schema[3]['constraints']);
        $this->assertSame(['kind' => 'file', 'kilobytes' => ['max' => 512], 'image' => true], $schema[4]['constraints']);
    }
}
