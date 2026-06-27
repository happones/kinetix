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
     */
    public function __construct(
        public ?string $heading,
        public array $events,
    ) {}
}
