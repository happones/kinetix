<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class SummaryData extends Data
{
    public function __construct(
        public ?string $label,
        public string $value,
    ) {}
}
