<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ColumnData extends Data
{
    /**
     * @param array<string, string>|null $options
     */
    public function __construct(
        public string $name,
        public string $label,
        public bool $isSearchable = false,
        public bool $isSortable = false,
        public string $alignment = 'left',
        public bool $isToggleable = false,
        public bool $isToggledHiddenByDefault = false,
        public string $type = 'text',
        // ColorColumn specific
        public ?bool $isCopyable = null,
        // ImageColumn specific
        public ?bool $isCircular = null,
        public ?int $size = null,
        public ?bool $isPreviewable = null,
        // SelectColumn specific
        public ?array $options = null,
        // TextColumn specific
        public ?bool $isBadge = null,
        public ?string $descriptionPosition = null,
        // TextInputColumn specific
        public ?string $inputType = null,
        public ?string $placeholder = null,
        // NumberInputColumn specific — {min,max,step,format,currency,decimals,locale}.
        public ?array $numberConfig = null,
        // Whether this column renders a summary in the footer.
        public bool $hasSummary = false,
        // ViewColumn specific
        public ?string $view = null,
    ) {}
}
