<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Happones\Kinetix\ReportsCenter\ReportSchedule;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ReportScheduleData extends Data
{
    /**
     * @param array<string, mixed>|null $parameters
     */
    public function __construct(
        public int|string $id,
        public string $reportClass,
        public string $reportLabel,
        public string $frequency,
        public ?array $parameters,
        public bool $enabled,
        public ?string $nextRunAt,
        public ?string $lastRunAt,
        public bool $notifyOnCompletion,
    ) {}

    public static function fromModel(ReportSchedule $schedule): self
    {
        return new self(
            id: $schedule->id,
            reportClass: $schedule->report_class,
            reportLabel: $schedule->report()->label(),
            frequency: $schedule->frequency->value,
            parameters: $schedule->parameters,
            enabled: $schedule->enabled,
            nextRunAt: $schedule->next_run_at?->toIso8601String(),
            lastRunAt: $schedule->last_run_at?->toIso8601String(),
            notifyOnCompletion: $schedule->notify_on_completion,
        );
    }
}
