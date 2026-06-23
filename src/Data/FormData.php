<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class FormData extends Data
{
    /**
     * @param array<int, FormFieldData>         $schema
     * @param array<string, mixed>              $data
     * @param array<string, array<int, string>> $rules
     */
    public function __construct(
        public array $schema,
        public array $data,
        public array $rules,
        public string $operation = 'create',
    ) {}
}
