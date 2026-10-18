<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

/**
 * A label an app declares in config or at boot — a translation key or a
 * plain string a JSON lang file translates — read in the reader's language
 * when it's shown.
 */
class KinetixTrans
{
    /**
     * The label translated; Kinetix's own keys read from the package when
     * the app hasn't published (or has trimmed) its copy of the
     * translations; a string with no translation as written.
     */
    public static function line(string $label): string
    {
        $translated = __($label);

        if ($translated === $label && str_starts_with($label, 'kinetix.')) {
            $translated = __('kinetix::'.$label);
        }

        return is_string($translated) && $translated !== 'kinetix::'.$label ? $translated : $label;
    }
}
