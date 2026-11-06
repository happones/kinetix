<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Forms\Components\CheckboxList;
use Happones\Kinetix\Forms\Components\Radio;
use Happones\Kinetix\Forms\Components\Select;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Forms\Rules\InOptions;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Validation\ValidationException;

class FormStrictOptionsTest extends TestCase
{
    private function form(bool $strict = true): Form
    {
        return Form::make()->schema([
            Select::make('color')->options(['red' => 'Red', 'green' => 'Green']),
            Radio::make('size')->options(['1' => 'S', '2' => 'M']),
            CheckboxList::make('tags')->options(fn (): array => ['a' => 'A', 'b' => 'B']),
            Select::make('grouped')->options(['Warm' => ['red' => 'Red'], 'Cool' => ['blue' => 'Blue']]),
            Select::make('remote')->searchUsing(\stdClass::class),
        ])->strictOptions($strict);
    }

    /**
     * @param  array<string, mixed> $input
     * @return array<int, string>
     */
    private function failing(array $input, bool $strict = true): array
    {
        try {
            $this->form($strict)->validate($input);
        } catch (ValidationException $e) {
            return array_keys($e->errors());
        }

        return [];
    }

    public function test_foreign_keys_are_rejected_on_every_option_field(): void
    {
        $this->assertSame(
            ['color', 'size', 'tags', 'grouped'],
            $this->failing(['color' => 'blue', 'size' => '9', 'tags' => ['a', 'z'], 'grouped' => 'green']),
        );
    }

    public function test_own_keys_empty_values_and_flattened_groups_pass(): void
    {
        $this->assertSame([], $this->failing(['color' => 'red', 'size' => '2', 'tags' => ['a', 'b'], 'grouped' => 'blue']));
        $this->assertSame([], $this->failing(['color' => null, 'size' => '', 'tags' => []]));
    }

    public function test_a_remote_search_select_is_not_constrained(): void
    {
        $this->assertSame([], $this->failing(['remote' => 'anything']));
    }

    public function test_without_strict_options_nothing_changes_and_no_rule_leaks_to_the_client(): void
    {
        $this->assertSame([], $this->failing(['color' => 'blue', 'tags' => ['z']], strict: false));

        $strict = $this->form()->toArray()['rules'];
        $plain  = $this->form(false)->toArray()['rules'];

        $this->assertSame($plain, $strict);
    }

    public function test_a_related_key_must_match_exactly_even_when_the_database_casts(): void
    {
        // A database may match '1abc' to row 1 (an integer cast): the keys it
        // returns are what is compared, as strings.
        $rule = new InOptions(null, static fn (array $ids): array => array_map(static fn (string $id): int => (int) $id, $ids));

        $failed = false;
        $rule->validate('branches', ['1abc'], function () use (&$failed) {
            $failed = true;

            return new class
            {
                public function translate(): void {}
            };
        });
        $this->assertTrue($failed);

        $rule->validate('branches', ['1'], static fn () => throw new \RuntimeException('should pass'));
        $this->assertTrue(true);
    }
}
