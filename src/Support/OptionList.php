<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

/**
 * Options (value => label) as they travel to the browser, in their order.
 *
 * A JavaScript object lists its integer-like keys first, ascending, whatever
 * order PHP built it in — so `pluck('name', 'id')` ordered by name would
 * render ordered by id. When that would happen the options go as a list of
 * `{value, label}`, which keeps the order; otherwise the map goes unchanged.
 */
final class OptionList
{
    /**
     * @param  array<int|string, mixed>|null                                                    $options
     * @return array<int|string, mixed>|array<int, array{value: int|string, label: mixed}>|null
     */
    public static function forWire(?array $options): ?array
    {
        if ($options === null || $options === [] || array_is_list($options) && self::isOptionList($options)) {
            return $options;
        }

        if (! self::wouldReorder(array_keys($options))) {
            return $options;
        }

        $list = [];

        foreach ($options as $value => $label) {
            $list[] = ['value' => $value, 'label' => $label];
        }

        return $list;
    }

    /**
     * Whether a JavaScript object with these keys would enumerate them in a
     * different order: array-index keys first, ascending, then the rest.
     *
     * @param array<int, int|string> $keys
     */
    private static function wouldReorder(array $keys): bool
    {
        $indexes = [];
        $others  = [];

        foreach ($keys as $key) {
            if (self::isArrayIndex((string) $key)) {
                $indexes[] = (int) $key;
            } else {
                $others[] = $key;
            }
        }

        if ($indexes === []) {
            return false;
        }

        $sorted = $indexes;
        sort($sorted);

        return array_map('strval', $keys) !== array_map('strval', [...$sorted, ...$others]);
    }

    /**
     * "0", "7", "42" — not "007", "-1" or "1.5", which JavaScript keeps in
     * insertion order.
     */
    private static function isArrayIndex(string $key): bool
    {
        return preg_match('/^(0|[1-9]\d{0,9})$/', $key) === 1 && (int) $key < 4294967295;
    }

    /**
     * @param array<int, mixed> $options
     */
    private static function isOptionList(array $options): bool
    {
        $first = $options[0];

        return is_array($first) && array_key_exists('value', $first) && array_key_exists('label', $first);
    }
}
