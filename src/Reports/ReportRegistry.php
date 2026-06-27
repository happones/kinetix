<?php

declare(strict_types=1);

namespace Happones\Kinetix\Reports;

/**
 * App-wide registry of {@see ScheduledReport} definitions, keyed by report key.
 */
class ReportRegistry
{
    /**
     * @var array<string, ScheduledReport>
     */
    protected array $reports = [];

    public function register(ScheduledReport $report): void
    {
        $this->reports[$report->getKey()] = $report;
    }

    public function get(string $key): ?ScheduledReport
    {
        return $this->reports[$key] ?? null;
    }

    /**
     * @return array<string, ScheduledReport>
     */
    public function all(): array
    {
        return $this->reports;
    }

    /**
     * Enabled reports, optionally filtered by frequency.
     *
     * @return array<int, ScheduledReport>
     */
    public function due(?string $frequency = null): array
    {
        return array_values(array_filter(
            $this->reports,
            static fn (ScheduledReport $report): bool => $report->isEnabled()
                && ($frequency === null || $report->getFrequency() === $frequency),
        ));
    }
}
