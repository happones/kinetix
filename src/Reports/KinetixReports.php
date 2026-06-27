<?php

declare(strict_types=1);

namespace Happones\Kinetix\Reports;

/**
 * Static entry point for registering scheduled reports (e.g. in a service
 * provider's boot()):
 *
 *     KinetixReports::register(
 *         ScheduledReport::make('daily-orders')->exporter(OrdersExporter::class)
 *             ->frequency('daily')->to(['ops@acme.com'])
 *     );
 */
class KinetixReports
{
    public static function registry(): ReportRegistry
    {
        return app(ReportRegistry::class);
    }

    public static function register(ScheduledReport $report): void
    {
        static::registry()->register($report);
    }

    /**
     * @return array<string, ScheduledReport>
     */
    public static function all(): array
    {
        return static::registry()->all();
    }
}
