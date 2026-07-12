<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

/**
 * Timezone helpers shared by date/calendar components.
 */
class KinetixTimezone
{
    /**
     * The application's configured IANA timezone (e.g. `America/Mexico_City`),
     * falling back to UTC.
     */
    public static function default(): string
    {
        return config('app.timezone', 'UTC');
    }
}
