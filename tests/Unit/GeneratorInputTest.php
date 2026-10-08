<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Forms\Components\GeneratorInput;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Tests\TestCase;

/**
 * GeneratorInput serializes a `generatorConfig` the frontend generator reads;
 * the presets (password/pin/username) are bundles of the same knobs.
 */
class GeneratorInputTest extends TestCase
{
    private function configOf(GeneratorInput $field): array
    {
        $schema = Form::make()->schema([$field])->toArray()['schema'];

        return $schema[0]['generatorConfig'];
    }

    public function test_password_preset_serializes_charset_knobs(): void
    {
        $cfg = $this->configOf(
            GeneratorInput::make('password')->password(length: 24)->symbols(false)->excludeAmbiguous(),
        );

        $this->assertSame('password', $cfg['kind']);
        $this->assertSame(24, $cfg['length']);
        $this->assertFalse($cfg['symbols']);
        $this->assertTrue($cfg['lowercase']);
        $this->assertTrue($cfg['excludeAmbiguous']);
    }

    public function test_pin_preset_serializes_mode_and_length(): void
    {
        $cfg = $this->configOf(GeneratorInput::make('pin')->pin(length: 4, mode: 'alphanum'));

        $this->assertSame('pin', $cfg['kind']);
        $this->assertSame(4, $cfg['length']);
        $this->assertSame('alphanum', $cfg['pinMode']);
    }

    public function test_pin_mode_falls_back_to_numeric_for_an_unknown_mode(): void
    {
        $cfg = $this->configOf(GeneratorInput::make('pin')->pin(mode: 'bogus'));

        $this->assertSame('numeric', $cfg['pinMode']);
    }

    public function test_username_preset_serializes_pattern_and_separator(): void
    {
        $cfg = $this->configOf(
            GeneratorInput::make('username')->username()->pattern('{first_name}.{last_name}')->separator('_'),
        );

        $this->assertSame('username', $cfg['kind']);
        $this->assertSame('{first_name}.{last_name}', $cfg['pattern']);
        $this->assertSame('_', $cfg['separator']);
    }

    public function test_copyable_and_masked_flags(): void
    {
        $cfg = $this->configOf(
            GeneratorInput::make('password')->password()->copyable()->masked(),
        );

        $this->assertTrue($cfg['copyable']);
        $this->assertFalse($cfg['revealable']); // masked() ⇒ not revealable by default
    }

    public function test_field_type_is_generator_input(): void
    {
        $schema = Form::make()->schema([GeneratorInput::make('x')->password()])->toArray()['schema'];
        $this->assertSame('generator-input', $schema[0]['type']);
    }
}
