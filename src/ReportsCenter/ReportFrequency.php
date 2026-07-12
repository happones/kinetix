<?php

declare(strict_types=1);

namespace Happones\Kinetix\ReportsCenter;

use Carbon\CarbonInterface;

/**
 * Recurrence cadence of a `ReportSchedule`.
 */
enum ReportFrequency: string
{
    case Once    = 'once';
    case Daily   = 'daily';
    case Weekly  = 'weekly';
    case Monthly = 'monthly';

    /**
     * The next run instant after `$from`, or `null` for `Once` (it never
     * recurs — the schedule disables itself once it has fired).
     */
    public function next(CarbonInterface $from): ?CarbonInterface
    {
        return match ($this) {
            self::Once    => null,
            self::Daily   => $from->clone()->addDay(),
            self::Weekly  => $from->clone()->addWeek(),
            self::Monthly => $from->clone()->addMonth(),
        };
    }
}
