<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class PdfTemplateData extends Data
{
    /**
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, mixed>             $settings
     * @param array<string, mixed>             $defaults
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $fields,
        public array $settings,
        public array $defaults,
        public bool $hasLogo,
    ) {}
}
