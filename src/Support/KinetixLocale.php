<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

/**
 * Locale helpers shared by date/number components.
 */
class KinetixLocale
{
    /**
     * The application locale as a BCP-47 tag for JS Intl / Reka calendars
     * (Laravel's `es_MX` → `es-MX`).
     */
    public static function bcp47(): string
    {
        return str_replace('_', '-', app()->getLocale());
    }
}
