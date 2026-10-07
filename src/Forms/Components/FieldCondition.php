<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

/**
 * A serializable condition comparing ANOTHER field's value — the building block
 * of client-side conditional visibility/disable/require (`visibleWhen()`,
 * `hiddenWhen()`, `requiredWhen()`, `disabledWhen()`).
 *
 * It is deliberately NOT a closure: it ships to the browser as plain data so
 * `KinetixForm` evaluates it live against the current form state (no server
 * round-trip), and it also lowers to a native Laravel rule so the SERVER
 * enforces the same thing on submit (the client is never the only guard).
 *
 * Operators:
 *   - `equals` / `notEquals` — strict value comparison;
 *   - `in` / `notIn`         — membership in a list;
 *   - `truthy` / `falsy`     — JS-truthiness of the other field (value ignored);
 *   - `filled` / `blank`     — presence (non-empty / empty).
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

    public function __construct(
        public readonly string $field,
        public readonly string $operator,
        public readonly mixed $value = null,
    ) {}

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
            self::EQUALS     => $actual === $this->value || (string) $actual === (string) $this->value,
            self::NOT_EQUALS => ! ($actual === $this->value || (string) $actual === (string) $this->value),
            self::IN         => is_array($this->value) && $this->containsLoose($this->value, $actual),
            self::NOT_IN     => ! (is_array($this->value) && $this->containsLoose($this->value, $actual)),
            self::TRUTHY     => $this->isTruthy($actual),
            self::FALSY      => ! $this->isTruthy($actual),
            self::FILLED     => $actual !== null && $actual !== '' && $actual !== [],
            self::BLANK      => $actual === null || $actual === '' || $actual === [],
            default          => false,
        };
    }

    /**
     * @param array<int, mixed> $haystack
     */
    private function containsLoose(array $haystack, mixed $needle): bool
    {
        foreach ($haystack as $candidate) {
            if ($candidate === $needle || (string) $candidate === (string) $needle) {
                return true;
            }
        }

        return false;
    }

    private function isTruthy(mixed $value): bool
    {
        return ! in_array($value, [null, false, 0, '0', '', []], true);
    }
}
