<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Forms\Components\GeneratorInput;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

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

    public function test_named_preset_is_serialized(): void
    {
        $cfg = $this->configOf(GeneratorInput::make('token')->preset('api-key'));

        $this->assertSame('api-key', $cfg['preset']);
    }

    public function test_custom_alphabet_sets_charset_strategy(): void
    {
        $cfg = $this->configOf(GeneratorInput::make('code')->custom(alphabet: 'ABC123', length: 8));

        $this->assertSame('charset', $cfg['strategy']);
        $this->assertSame('ABC123', $cfg['alphabet']);
        $this->assertSame(8, $cfg['length']);
    }

    public function test_custom_mask_sets_mask_strategy(): void
    {
        $cfg = $this->configOf(GeneratorInput::make('ref')->custom(mask: 'INV-####-AA'));

        $this->assertSame('mask', $cfg['strategy']);
        $this->assertSame('INV-####-AA', $cfg['mask']);
    }

    public function test_words_strategy_serializes_its_knobs(): void
    {
        $cfg = $this->configOf(GeneratorInput::make('nick')->words(2, separator: '_', appendDigits: 3));

        $this->assertSame('words', $cfg['strategy']);
        $this->assertSame(2, $cfg['words']);
        $this->assertSame('_', $cfg['wordSeparator']);
        $this->assertSame(3, $cfg['appendDigits']);
    }

    public function test_prefix_is_serialized(): void
    {
        $cfg = $this->configOf(GeneratorInput::make('k')->preset('api-key')->valuePrefix('live_'));

        $this->assertSame('live_', $cfg['prefix']);
    }

    public function test_preset_catalog_constant_lists_the_presets(): void
    {
        $this->assertContains('uuid', GeneratorInput::PRESETS);
        $this->assertContains('license-key', GeneratorInput::PRESETS);
        $this->assertContains('password-strong', GeneratorInput::PRESETS);
    }

    public function test_preset_catalog_constant_matches_the_frontend_catalog(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../resources/js/composables/useKinetixGenerator.ts');
        preg_match('/GENERATOR_PRESETS[^=]*=\s*\{(.*?)\n\};/s', $source, $catalog);
        preg_match_all("/^    '?([a-z0-9-]+)'?: \\{/m", $catalog[1] ?? '', $keys);

        $this->assertEqualsCanonicalizing($keys[1], GeneratorInput::PRESETS);
    }

    public function test_an_unknown_preset_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown generator preset [pasword-strong]');

        GeneratorInput::make('password')->preset('pasword-strong');
    }

    public function test_a_preset_config_omits_the_defaults_it_would_override(): void
    {
        $cfg = $this->configOf(GeneratorInput::make('token')->preset('api-key'));

        // A default length 16 / pinMode numeric here overrode the preset and
        // made every preset generate 16 digits.
        $this->assertArrayNotHasKey('length', $cfg);
        $this->assertArrayNotHasKey('pinMode', $cfg);
        $this->assertArrayNotHasKey('strategy', $cfg);
        $this->assertArrayNotHasKey('symbols', $cfg);
    }

    /**
     * The generator config is a contract between this class and the frontend
     * engine. Both sides check the same fixture: here the serialized output
     * must equal it, and `useKinetixGenerator.spec.ts` generates from each
     * entry and asserts the value's shape. Change one side, update the fixture,
     * and the other side's test tells you whether they still agree.
     *
     * @return array<string, callable(): GeneratorInput>
     */
    private static function contractBuilders(): array
    {
        return [
            'legacy-default'                => fn () => GeneratorInput::make('x'),
            'legacy-password-24-no-symbols' => fn () => GeneratorInput::make('x')->password(24)->symbols(false),
            'legacy-pin-alphanum'           => fn () => GeneratorInput::make('x')->pin(4, 'alphanum'),
            'legacy-pin-numeric'            => fn () => GeneratorInput::make('x')->pin(6),
            'legacy-username-pattern'       => fn () => GeneratorInput::make('x')->username()->pattern('{first}.{last}'),
            'legacy-username-random'        => fn () => GeneratorInput::make('x')->username(10),
            'preset-uuid'                   => fn () => GeneratorInput::make('x')->preset('uuid'),
            'preset-api-key'                => fn () => GeneratorInput::make('x')->preset('api-key'),
            'preset-license-key'            => fn () => GeneratorInput::make('x')->preset('license-key'),
            'preset-password-simple'        => fn () => GeneratorInput::make('x')->preset('password-simple'),
            'preset-password-strong-24'     => fn () => GeneratorInput::make('x')->preset('password-strong')->length(24),
            'preset-passphrase'             => fn () => GeneratorInput::make('x')->preset('passphrase'),
            'custom-alphabet'               => fn () => GeneratorInput::make('x')->custom(alphabet: 'ABC123', length: 8),
            'custom-mask'                   => fn () => GeneratorInput::make('x')->custom(mask: 'INV-####-AA'),
            'words'                         => fn () => GeneratorInput::make('x')->words(2, '_', 3),
        ];
    }

    /**
     * @return iterable<string, array{0: string, 1: callable(): GeneratorInput}>
     */
    public static function contractCases(): iterable
    {
        foreach (self::contractBuilders() as $name => $build) {
            yield $name => [$name, $build];
        }
    }

    /**
     * @param callable(): GeneratorInput $build
     */
    #[DataProvider('contractCases')]
    public function test_serialized_config_matches_the_shared_contract_fixture(string $name, callable $build): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__.'/../js/fixtures/generator-configs.json'), true);

        $this->assertArrayHasKey($name, $fixture);

        $expected = $fixture[$name];
        $actual   = $this->configOf($build());
        ksort($expected);
        ksort($actual);

        $this->assertSame($expected, $actual);
    }

    public function test_every_contract_fixture_entry_has_a_php_case(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__.'/../js/fixtures/generator-configs.json'), true);

        $this->assertEqualsCanonicalizing(array_keys($fixture), array_keys(self::contractBuilders()));
    }
}
