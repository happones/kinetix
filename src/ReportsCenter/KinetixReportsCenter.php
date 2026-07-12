<?php

declare(strict_types=1);

namespace Happones\Kinetix\ReportsCenter;

/**
 * Static entry point for registering `Report` types living outside the
 * auto-discovered directory (e.g. in a service provider's boot()):
 *
 *     KinetixReportsCenter::register(MonthlyInvoicesReport::class);
 */
class KinetixReportsCenter
{
    public static function registry(): ReportRegistry
    {
        return app(ReportRegistry::class);
    }

    /**
     * @param class-string<Report> $reportClass
     */
    public static function register(string $reportClass): void
    {
        static::registry()->register($reportClass);
    }

    /**
     * @return array<int, class-string<Report>>
     */
    public static function all(): array
    {
        return static::registry()->all();
    }
}
