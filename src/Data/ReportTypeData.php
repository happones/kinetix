<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Happones\Kinetix\ReportsCenter\Report;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ReportTypeData extends Data
{
    public function __construct(
        public string $token,
        public string $label,
        public ?string $description,
        public string $format,
    ) {}

    /**
     * @param class-string<Report> $reportClass
     */
    public static function fromClass(string $reportClass): self
    {
        $report = new $reportClass;

        return new self(
            token: $reportClass::token(),
            label: $report->label(),
            description: $report->description(),
            format: $report->format(),
        );
    }
}
