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
        $casts = (new static)->getCasts();

        return array_keys(array_filter(
            $casts,
            static fn (string $cast): bool => $cast === ConfidentialCast::class
                || str_starts_with($cast, ConfidentialCast::class.':'),
        ));
    }
}
