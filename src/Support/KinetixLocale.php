<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

/**
 * Locale helpers shared by date/number components.
 */
class KinetixLocale
{
    /**
     * The application locale as a BCP-47 tag for JS Intl / Reka calendars and
     * number/currency formatting (Laravel's `es_MX` → `es-MX`), in the region
     * `kinetix.locale.regions` gives the language: an app on `es` formatting
     * as Mexico maps `'es' => 'es-MX'`. Without a region, plain `es` groups
     * five-digit numbers but not four-digit ones — "$1199" next to
     * "$12.999" — and uses Spain's separators.
     */
    public static function bcp47(): string
    {
        $locale = (string) app()->getLocale();
        $region = config('kinetix.locale.regions.'.$locale);

        return str_replace('_', '-', is_string($region) && $region !== '' ? $region : $locale);
    }
}
