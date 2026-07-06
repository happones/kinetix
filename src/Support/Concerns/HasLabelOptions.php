<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support\Concerns;

use BackedEnum;
use Happones\Kinetix\Support\Contracts\HasLabel;

trait HasLabelOptions
{
    /**
     * Get options list for select dropdowns.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $label = ($case instanceof HasLabel || (is_object($case) && method_exists($case, 'getLabel')))
                ? $case->getLabel()
                : ($case instanceof BackedEnum ? $case->value : $case->name);

            $value                    = $case instanceof BackedEnum ? $case->value : $case->name;
            $options[(string) $value] = $label;
        }

        return $options;
    }
}
