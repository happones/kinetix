<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Happones\Kinetix\Tables\TableStat;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One KPI card rendered above a table (see {@see TableStat}).
 * The value arrives already aggregated and formatted, so the frontend only lays
 * it out.
 */
#[TypeScript]
class TableStatData extends Data
{
    /**
     * @param array<int, float|int> $chart
     */
    public function __construct(
        public string $label,
        public string $value,
        public ?string $icon = null,
        public string $color = 'info',
        public ?string $description = null,
        public ?string $descriptionIcon = null,
        public ?string $descriptionColor = null,
        public array $chart = [],
        public ?string $url = null,
    ) {}
}
