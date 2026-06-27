<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class FilterData extends Data
{
    /**
     * @param array<string, string>|null $options
     */
    public function __construct(
        public string $name,
        public string $label,
        public mixed $default,
        public string $type,
        // SelectFilter specific
        public ?array $options = null,
        // DateRangeFilter specific — render the shadcn-style range calendar
        public bool $useCalendar = false,
        public int $numberOfMonths = 1,
        public ?string $locale = null,
        public ?string $weekdayFormat = null,
        public bool $fixedWeeks = false,
        public ?string $minValue = null,
        public ?string $maxValue = null,
        // DateTimeFilter specific — minute granularity + 12h clock toggle.
        public int $minuteStep = 5,
        public bool $hour12 = false,
        // WeekFilter — first day of week (0=Sun … 6=Sat).
        public ?int $weekStartsOn = null,
    ) {}
}
