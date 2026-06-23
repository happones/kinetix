<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class FormFieldData extends Data
{
    /**
     * @param array<string, mixed>|int|string|null $columnSpan
     * @param array<string, string>|null           $options
     * @param array<string, string>|null           $extraAttributes
     * @param array<string, string>|null           $extraInputAttributes
     * @param array<string, string>|null           $extraFieldWrapperAttributes
     * @param array<int, FormFieldData>|null       $schema
     */
    public function __construct(
        public string $type,
        public ?string $name = null,
        public ?string $label = null,
        public mixed $columnSpan = 'full',
        public ?string $placeholder = null,
        public mixed $defaultValue = null,
        public bool $isDisabled = false,
        public bool $isLive = false,
        public ?int $debounce = null,
        public ?string $prefix = null,
        public ?string $suffix = null,
        public ?string $inputType = null,
        public bool $isInline = false,
        public ?array $options = null,
        public ?array $extraAttributes = null,
        public ?array $extraInputAttributes = null,
        public ?array $extraFieldWrapperAttributes = null,
        // Layout components specific
        public ?array $schema = null,
        public ?string $heading = null,
        public ?string $description = null,
        public ?int $columns = null,
        // Repeater specific
        public ?int $minItems = null,
        public ?int $maxItems = null,
        public ?string $addActionLabel = null,
        // File upload specific
        public bool $isMultiple = false,
        public ?array $acceptedFileTypes = null,
        public ?int $maxSize = null,
        public bool $isImage = false,
        public ?int $maxFiles = null,
        public ?string $uploadToken = null,
        // Date / DateTime picker specific — shadcn calendar by default.
        public bool $useCalendar = false,
        public ?string $dateLocale = null,
        public int $minuteStep = 5,
        public bool $hour12 = false,
    ) {}
}
