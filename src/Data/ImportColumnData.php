<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ImportColumnData extends Data
{
    /**
     * @param array<int, string> $guesses
     */
    public function __construct(
        public string $name,
        public string $label,
        public bool $isRequired = false,
        public array $guesses = [],
    ) {}
}
