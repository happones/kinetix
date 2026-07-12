<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Exports\ExportColumn;
use Happones\Kinetix\ReportsCenter\Report;
use Happones\Kinetix\ReportsCenter\ReportRegistry;
use Happones\Kinetix\Tests\Feature\Fixtures\ReportsFixture\FixtureAlphaReport;
use Happones\Kinetix\Tests\Feature\Fixtures\ReportsFixture\FixtureBetaReport;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class ManuallyRegisteredReport extends Report
{
    protected static ?string $model = Model::class;

    public static function getColumns(): array
    {
        return [ExportColumn::make('id')];
    }
}

class NotAReportAtAll {}

class ReportRegistryTest extends TestCase
{
    protected string $fixturesPath = __DIR__.'/Fixtures/ReportsFixture';

    protected string $fixturesNamespace = 'Happones\\Kinetix\\Tests\\Feature\\Fixtures\\ReportsFixture';

    public function test_discovers_report_subclasses_from_a_directory(): void
    {
        $registry = new ReportRegistry;
        $registry->discover($this->fixturesPath, $this->fixturesNamespace);

        $all = $registry->all();

        $this->assertContains(FixtureAlphaReport::class, $all);
        $this->assertContains(FixtureBetaReport::class, $all);
    }

    public function test_excludes_abstract_classes_and_non_report_classes(): void
    {
        $registry = new ReportRegistry;
        $registry->discover($this->fixturesPath, $this->fixturesNamespace);

        $all = $registry->all();

        foreach ($all as $class) {
            $this->assertTrue(is_subclass_of($class, Report::class));
        }

        $this->assertNotContains($this->fixturesNamespace.'\\AbstractFixtureReport', $all);
        $this->assertNotContains($this->fixturesNamespace.'\\NotAReport', $all);
    }

    public function test_manual_register_merges_with_discovered_without_duplicates(): void
    {
        $registry = new ReportRegistry;
        $registry->discover($this->fixturesPath, $this->fixturesNamespace);
        $registry->register(ManuallyRegisteredReport::class);
        // Registering the same class twice must not duplicate it.
        $registry->register(ManuallyRegisteredReport::class);

        $all = $registry->all();

        $this->assertContains(ManuallyRegisteredReport::class, $all);
        $this->assertSame(count($all), count(array_unique($all)));
    }

    public function test_register_rejects_a_class_that_is_not_a_report(): void
    {
        $this->expectException(RuntimeException::class);

        (new ReportRegistry)->register(NotAReportAtAll::class);
    }

    public function test_get_returns_null_for_an_unknown_class(): void
    {
        $registry = new ReportRegistry;
        $registry->discover($this->fixturesPath, $this->fixturesNamespace);

        $this->assertNull($registry->get('Some\\Unknown\\Class'));
        $this->assertSame(FixtureAlphaReport::class, $registry->get(FixtureAlphaReport::class));
    }

    public function test_discover_on_a_missing_directory_is_a_noop(): void
    {
        $registry = new ReportRegistry;
        $registry->discover('/path/does/not/exist', 'Some\\Namespace');

        $this->assertSame([], $registry->all());
    }
}
