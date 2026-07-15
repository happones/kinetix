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
        // Live (Precognition) validation: when enabled the client validates
        // fields against the server as they change. `validationUrl` is optional
        // — the client falls back to the form's submit URL when it is null.
        public bool $precognitive = false,
        public ?string $validationUrl = null,
        public string $validationMethod = 'post',
    ) {}
}
