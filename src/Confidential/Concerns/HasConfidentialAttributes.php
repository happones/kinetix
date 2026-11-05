<?php

declare(strict_types=1);

namespace Happones\Kinetix\Confidential\Concerns;

use Happones\Kinetix\Confidential\Casts\ConfidentialCast;

/**
 * Marks a model as having confidential (encrypted + masked) attributes, and
 * exposes which ones they are for a host's own introspection/audit tooling.
 * Deliberately thin: this codebase never composes `casts()` via traits
 * (every model declares it standalone), so this does not touch `casts()` —
 * add `ConfidentialCast` there yourself; the actual encryption/masking
 * enforcement lives entirely in that cast, not in this trait.
 */
trait HasConfidentialAttributes
{
    /**
     * @return array<int, string>
     */
    public static function confidentialColumns(): array
    {
        $model = new static;

        return array_values(array_filter(
            array_keys($model->getCasts()),
            static fn (string $attribute): bool => ConfidentialCast::appliesTo($model, $attribute),
        ));
    }
}
