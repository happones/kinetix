<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\KinetixServiceProvider;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Support\ServiceProvider;

class ReportsCenterMigrationPublishTest extends TestCase
{
    public function test_reports_center_migrations_are_registered_for_publishing(): void
    {
        $paths = ServiceProvider::pathsToPublish(KinetixServiceProvider::class, 'kinetix-reports-center-migrations');

        $published = array_map('basename', array_values($paths));
        sort($published);

        $this->assertSame([
            '2026_01_01_000020_create_kinetix_report_schedules_table.php',
            '2026_01_01_000021_create_kinetix_report_runs_table.php',
        ], $published);
    }
}
