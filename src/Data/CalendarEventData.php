<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CalendarEventData extends Data
{
    /**
     * @param string  $start  ISO-8601 datetime (with UTC offset) — an absolute
     *                        instant, safe to re-render in any timezone client-side.
     * @param ?string $end    ISO-8601 datetime, or null for a point-in-time event.
     * @param bool    $allDay Auto-detected: true when start (and end, if set) fall
     *                        exactly at midnight — i.e. no meaningful time-of-day.
     */
    public function __construct(
        public int|string $id,
        public string $title,
        public string $start,
        public ?string $end = null,
        public bool $allDay = false,
        public ?string $color = null,
        public ?string $url = null,
        public ?string $description = null,
    ) {}
}
