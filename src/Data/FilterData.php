<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class FilterData extends Data
{
    /**
     * @param array<string, string>|null $options
     */
    public function __construct(
        public string $name,
        public string $label,
        public mixed $default,
        public string $type,
        // SelectFilter specific
        public ?array $options = null,
    ) {}
}
