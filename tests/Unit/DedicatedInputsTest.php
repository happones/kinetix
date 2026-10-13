<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Forms\Components\JsonInput;
use Happones\Kinetix\Forms\Components\KeyValue;
use Happones\Kinetix\Forms\Components\TimezoneSelect;
use Happones\Kinetix\Forms\FieldConstraints;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Tests\TestCase;

class DedicatedInputsTest extends TestCase
{
    public function test_a_json_input_opens_a_decoded_value_formatted(): void
    {
        $field = JsonInput::make('settings');

        $this->assertSame("{\n    \"theme\": \"dark\",\n    \"size\": 2\n}", $field->hydrate(['theme' => 'dark', 'size' => 2]));
        $this->assertSame('{"a":1}', $field->hydrate('{"a":1}'));
        $this->assertSame('{"a":1}', $field->dehydrate('{"a":1}'));
        $this->assertContains('json', $field->getRules());
    }

    public function test_a_decoded_json_input_saves_the_array(): void
    {
        $field = JsonInput::make('settings')->decoded();

        $this->assertSame(['a' => 1], $field->dehydrate('{"a":1}'));
        $this->assertNull($field->dehydrate('  '));
        // Validation refuses it first; nothing half-decoded is saved.
        $this->assertSame('{"a":', $field->dehydrate('{"a":'));
    }

    public function test_a_json_input_renders_as_one(): void
    {
        $schema = Form::make()->schema([JsonInput::make('settings')])->toArray()['schema'];

        $this->assertSame('json-input', $schema[0]['type']);
        $this->assertSame('json', $schema[0]['constraints']['format']);
    }

    public function test_the_time_zone_picker_lists_every_zone_with_its_offset(): void
    {
        $options = TimezoneSelect::timezoneOptions();

        $this->assertArrayHasKey('America/Mexico_City', $options);
        $this->assertMatchesRegularExpression('/^\(UTC[+−]\d{2}:\d{2}\) America\/Mexico City$/u', $options['America/Mexico_City']);
        $this->assertSame('(UTC+00:00) UTC', $options['UTC']);

        // Ordered by offset: the first zone is behind the last.
        $labels = array_values($options);
        $this->assertStringStartsWith('(UTC−', $labels[0]);
        $this->assertStringStartsWith('(UTC+', $labels[count($labels) - 1]);

        $field = TimezoneSelect::make('timezone');
        $this->assertContains('timezone', $field->getRules());
        $this->assertTrue(Form::make()->schema([$field])->toArray()['schema'][0]['isSearchable']);
    }

    public function test_key_value_keys_are_fixed_by_the_array_rule(): void
    {
        $field = KeyValue::make('meta')->keys(['title', 'color']);

        $this->assertContains('array:title,color', $field->getRules());
        $this->assertSame(['title', 'color'], FieldConstraints::from($field->getRules(), 'key-value')['keys']);
    }

    public function test_distinct_can_ignore_case(): void
    {
        $this->assertSame('ignore_case', FieldConstraints::from(['array', 'distinct:ignore_case'], 'tags-input')['distinct']);
        $this->assertTrue(FieldConstraints::from(['array', 'distinct'], 'tags-input')['distinct']);
    }
}
