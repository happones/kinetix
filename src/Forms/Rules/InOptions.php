<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Rules;

use Closure;
use Happones\Kinetix\Forms\Form;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accepts only values the field offered as options: a scalar among the keys,
 * or an array whose every element is. Empty values pass, so `required` stays
 * the job of `required`.
 *
 * @internal Added by {@see Form::strictOptions()}.
 */
final class InOptions implements ValidationRule
{
    /**
     * @param array<int, string>|null                             $keys    the accepted keys; null when `$related` decides
     * @param (Closure(array<int, string>): iterable<mixed>)|null $related the given keys that exist in the related table, as the table returns them
     */
    public function __construct(
        private readonly ?array $keys,
        private readonly ?Closure $related = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || $value === []) {
            return;
        }

        if (! $this->accepts($value)) {
            $fail('validation.in')->translate();
        }
    }

    private function accepts(mixed $value): bool
    {
        $items = is_array($value) ? $value : [$value];
        $ids   = [];

        foreach ($items as $item) {
            if (! is_scalar($item)) {
                return false;
            }

            $ids[(string) $item] = true;
        }

        $ids = array_map('strval', array_keys($ids));

        if ($this->related !== null) {
            $found = [];

            foreach (($this->related)($ids) as $key) {
                $found[(string) $key] = true;
            }

            // Compared as strings: a database may cast '1abc' to 1 when matching.
            return array_diff($ids, array_keys($found)) === [];
        }

        foreach ($ids as $id) {
            if (! in_array($id, $this->keys ?? [], true)) {
                return false;
            }
        }

        return true;
    }
}
