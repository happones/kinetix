<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature\Fixtures\ReportsFixture;

use Happones\Kinetix\ReportsCenter\Report;

/**
 * An abstract Report subclass in the discovered directory — must be
 * excluded from `ReportRegistry::all()` (it can't be instantiated).
 */
abstract class AbstractFixtureReport extends Report {}
