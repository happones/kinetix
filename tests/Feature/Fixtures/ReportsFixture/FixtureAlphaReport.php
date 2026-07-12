<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature\Fixtures\ReportsFixture;

use Happones\Kinetix\Exports\ExportColumn;
use Happones\Kinetix\ReportsCenter\Report;
use Illuminate\Database\Eloquent\Model;

class FixtureAlphaReport extends Report
{
    protected static ?string $model = Model::class;

    public static function getColumns(): array
    {
        return [ExportColumn::make('id')];
    }
}
