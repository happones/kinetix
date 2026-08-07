<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CalendarData extends Data
{
    /**
     * @param array<int, CalendarEventData> $events
     * @param string                        $timezone IANA timezone events are resolved against
     *                                                server-side (defaults to `config('app.timezone')`).
     *                                                The frontend may override it per-instance; since
     *                                                `start`/`end` are absolute instants, either
     *                                                timezone renders the same events correctly.
     */
    public function __construct(
        public ?string $heading,
        public array $events,
        public string $timezone,
        /**
         * Encrypted descriptor {model, dateColumn, endColumn} for the move
         * endpoint — null unless the calendar opted in via `moveable()`.
         */
        public ?string $model = null,
    ) {}
}
