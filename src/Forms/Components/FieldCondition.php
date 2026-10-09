<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use InvalidArgumentException;

/**
 * A serializable condition comparing ANOTHER field's value — the building block
 * of client-side conditional visibility/disable/require (`visibleWhen()`,
 * `hiddenWhen()`, `requiredWhen()`, `disabledWhen()`).
 *
 * It is deliberately NOT a closure: it ships to the browser as plain data so
 * `KinetixForm` evaluates it live against the current form state (no server
 * round-trip), and the SERVER evaluates the same condition on submit to decide
 * what to validate and persist (the client is never the only guard).
 *
 * The two evaluations MUST agree — a field the browser shows but the server
 * thinks hidden is silently dropped on save. So the semantics are defined once
 * and pinned by a fixture both test suites run
 * (`tests/js/fixtures/field-conditions.json`):
 *
 *   - `equals` / `notEquals` — against a boolean, both sides compare as
 *     booleans (an untouched toggle is off); otherwise as text (`1` = `'1'`,
 *     null = `''`, a float written as JavaScript writes it: `1e+21`, `-0` is
 *     `0`). A list value matches when it CONTAINS the expected value (two
 *     lists: the same members). A key/value map never equals a scalar. An
 *     expected map counts as the list of its values.
 *   - `in` / `notIn`         — any member of the list matches (for a list
 *     value: the two lists intersect).
 *   - `truthy` / `falsy`     — falsy is null, false, 0, `''`, `'0'`, `'false'`
 *     and an empty list or map.
 *   - `filled` / `blank`     — blank is null, a whitespace-only string and an
 *     empty list or map; numbers and booleans are always filled.
 *     Whitespace is ASCII (space, tab, newlines, NUL, vertical tab): a
 *     non-breaking space is text.
 */
final class FieldCondition
{
    public const EQUALS = 'equals';

    public const NOT_EQUALS = 'notEquals';

    public const IN = 'in';

    public const NOT_IN = 'notIn';

    public const TRUTHY = 'truthy';

    public const FALSY = 'falsy';

    public const FILLED = 'filled';

    public const BLANK = 'blank';

    public const OPERATORS = [
        self::EQUALS, self::NOT_EQUALS, self::IN, self::NOT_IN,
        self::TRUTHY, self::FALSY, self::FILLED, self::BLANK,
    ];

    public function __construct(
        public readonly string $field,
        public readonly string $operator,
        public readonly mixed $value = null,
    ) {
        // A typo used to evaluate as "never" on the server and "always" in
        // the browser: the field showed, and its value was dropped on save.
        if (! in_array($operator, self::OPERATORS, true)) {
            throw new InvalidArgumentException("Unknown condition operator [{$operator}] on [{$field}].");
        }

        if (in_array($operator, [self::IN, self::NOT_IN], true) && ! is_array($value)) {
            throw new InvalidArgumentException("The [{$operator}] condition on [{$field}] needs a list of values.");
        }
    }

    /**
     * Serialize to the shape `KinetixForm` evaluates on the client.
     *
     * @return array{field: string, operator: string, value: mixed}
     */
    public function toArray(): array
    {
        return [
            'field'    => $this->field,
            'operator' => $this->operator,
            'value'    => $this->value,
        ];
    }

    /**
     * Whether the condition holds against a set of submitted values — the
     * server-side mirror of the client evaluation, used to decide if a
     * conditionally-hidden field should be excluded or a conditionally-required
     * one enforced.
     *
     * @param array<string, mixed> $data
     */
    public function passes(array $data): bool
    {
        $actual = $data[$this->field] ?? null;

        return match ($this->operator) {
            self::EQUALS     => self::matches($actual, $this->value),
            self::NOT_EQUALS => ! self::matches($actual, $this->value),
            self::IN         => self::isIn($actual, $this->value),
            self::NOT_IN     => ! self::isIn($actual, $this->value),
            self::TRUTHY     => self::toBool($actual),
            self::FALSY      => ! self::toBool($actual),
            self::FILLED     => ! self::isBlank($actual),
            default          => self::isBlank($actual),
        };
    }

    private static function matches(mixed $actual, mixed $expected): bool
    {
        if (is_array($actual)) {
            if (! array_is_list($actual)) {
                return false;
            }

            if (is_array($expected)) {
                return self::sameMembers($actual, array_values($expected));
            }

            foreach ($actual as $item) {
                if (self::same($item, $expected)) {
                    return true;
                }
            }

            return false;
        }

        return ! is_array($expected) && self::same($actual, $expected);
    }

    private static function isIn(mixed $actual, mixed $list): bool
    {
        if (! is_array($list)) {
            return false;
        }

        $candidates = is_array($actual)
            ? (array_is_list($actual) ? $actual : [])
            : [$actual];

        foreach ($candidates as $candidate) {
            foreach ($list as $item) {
                if (self::same($candidate, $item)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<int|string, mixed> $a
     * @param array<int|string, mixed> $b
     */
    private static function sameMembers(array $a, array $b): bool
    {
        $texts = static function (array $list): array {
            $out = array_map(static fn (mixed $item): string => self::text($item), array_values($list));
            // As text: numeric strings compared as numbers ('1' and '1.0')
            // left the order, and the result, to the input order.
            sort($out, SORT_STRING);

            return $out;
        };

        return $texts($a) === $texts($b);
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if (is_array($a) || is_array($b) || is_object($a) || is_object($b)) {
            return false;
        }

        if (is_bool($a) || is_bool($b)) {
            return self::toBool($a) === self::toBool($b);
        }

        return self::text($a) === self::text($b);
    }

    private static function text(mixed $value): string
    {
        return match (true) {
            $value === null   => '',
            is_bool($value)   => $value ? '1' : '0',
            is_float($value)  => self::floatText($value),
            is_scalar($value) => (string) $value,
            default           => '',
        };
    }

    /**
     * A float as JavaScript's `String(n)` writes it: the shortest digits
     * that round-trip, in plain notation from 1e-7 up to 1e21 and in
     * exponent notation (`1e+21`, `1.5e-7`) outside it. PHP's own cast
     * rounds to 14 digits and writes `1.0E+21`.
     */
    private static function floatText(float $value): string
    {
        if (is_nan($value)) {
            return 'NaN';
        }

        if (is_infinite($value)) {
            return $value > 0 ? 'Infinity' : '-Infinity';
        }

        if ($value == 0.0) {
            return '0';
        }

        // The shortest round-trip digits, e.g. `1.0E+21`, `0.30000000000000004`.
        $repr                  = var_export(abs($value), true);
        [$mantissa, $exponent] = explode('E', strtoupper($repr)) + [1 => '0'];
        [$whole, $fraction]    = explode('.', $mantissa)         + [1 => ''];

        $all    = $whole.$fraction;
        $digits = rtrim(ltrim($all, '0'), '0');
        // The value is 0.<digits> × 10^$point.
        $point = strlen($whole) + (int) $exponent - (strlen($all) - strlen(ltrim($all, '0')));
        $count = strlen($digits);
        $sign  = $value < 0 ? '-' : '';

        if ($count <= $point && $point <= 21) {
            return $sign.$digits.str_repeat('0', $point - $count);
        }

        if ($point > 0 && $point <= 21) {
            return $sign.substr($digits, 0, $point).'.'.substr($digits, $point);
        }

        if ($point > -6 && $point <= 0) {
            return $sign.'0.'.str_repeat('0', -$point).$digits;
        }

        $mantissaText = $count === 1 ? $digits : $digits[0].'.'.substr($digits, 1);

        return $sign.$mantissaText.'e'.($point - 1 < 0 ? '-' : '+').abs($point - 1);
    }

    private static function toBool(mixed $value): bool
    {
        return match (true) {
            $value === null                  => false,
            is_bool($value)                  => $value,
            is_int($value), is_float($value) => $value != 0,
            is_string($value)                => ! in_array(strtolower(trim($value)), ['', '0', 'false'], true),
            is_array($value)                 => $value !== [],
            default                          => true,
        };
    }

    private static function isBlank(mixed $value): bool
    {
        return $value === null
            || (is_string($value) && trim($value) === '')
            || $value === [];
    }
}
