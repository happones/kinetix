<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\ReportsCenter\ReportFrequency;
use Happones\Kinetix\Tests\TestCase;

/**
 * The schedules list showed the stored value ("weekly") instead of a word in
 * the visitor's language.
 */
class ReportFrequencyTest extends TestCase
{
    public function test_a_cadence_reads_through_the_translator(): void
    {
        app('translator')->addLines(['kinetix.report_frequency_weekly' => 'Semanal'], 'es');
        app()->setLocale('es');

        $this->assertSame('Semanal', ReportFrequency::Weekly->getLabel());
    }

    public function test_every_shipped_locale_names_every_cadence(): void
    {
        foreach (glob(__DIR__.'/../../resources/lang/*/kinetix.php') ?: [] as $path) {
            $lines = require $path;

            foreach (ReportFrequency::cases() as $frequency) {
                $this->assertArrayHasKey('report_frequency_'.$frequency->value, $lines, $path);
            }
        }
    }
}
