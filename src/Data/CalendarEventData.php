<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CalendarEventData extends Data
{
    public function __construct(
        public int|string $id,
        public string $title,
        public string $start,
        public ?string $end = null,
        public ?string $color = null,
        public ?string $url = null,
    ) {}
}
