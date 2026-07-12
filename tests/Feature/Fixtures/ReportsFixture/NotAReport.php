<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature\Fixtures\ReportsFixture;

/**
 * Lives in the discovered directory but does NOT extend `Report` — must be
 * excluded from `ReportRegistry::all()`.
 */
class NotAReport {}
