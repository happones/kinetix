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
        public bool $isRequired = false,
        public bool $isLive = false,
        public ?int $debounce = null,
        public ?string $prefix = null,
        public ?string $suffix = null,
        public ?string $inputType = null,
        public bool $isCopyable = false,
        public bool $isRevealable = false,
        public bool $isInline = false,
        public ?array $options = null,
        // Select searchable (combobox). `searchToken` (when set) points the
        // search box at a remote, server-filtered source.
        public bool $isSearchable = false,
        public ?string $searchToken = null,
        public ?array $extraAttributes = null,
        public ?array $extraInputAttributes = null,
        public ?array $extraFieldWrapperAttributes = null,
        // Layout components specific
        public ?array $schema = null,
        public ?string $heading = null,
        public ?string $description = null,
        public ?int $columns = null,
        public ?string $icon = null,
        // Placeholder content (read-only display component).
        public ?string $content = null,
        // Wizard layout: indicator variant + optional gating slug.
        public ?string $variant = null,
        public ?string $orientation = null,
        public ?bool $fullWidth = null,
        public ?string $slug = null,
        // Repeater specific
        public ?int $minItems = null,
        public ?int $maxItems = null,
        public ?string $addActionLabel = null,
        // Table repeater specific
        public bool $autosave = false,
        public ?string $autosaveToken = null,
        public ?array $summarize = null,
        public bool $exportable = false,
        // File upload specific
        public bool $isMultiple = false,
        public ?array $acceptedFileTypes = null,
        public ?int $maxSize = null,
        public bool $isImage = false,
        public ?int $maxFiles = null,
        public ?string $uploadToken = null,
        // Media library specific
        public ?string $mediaCollection = null,
        public ?array $mediaConversions = null,
        public bool $isReorderable = false,
        // Date / DateTime picker specific — shadcn calendar by default.
        public bool $useCalendar = false,
        public ?string $dateLocale = null,
        public int $minuteStep = 5,
        public bool $hour12 = false,
        // Native-style range bounds (date/month/week/year pickers).
        public ?string $minValue = null,
        public ?string $maxValue = null,
        // Range-calendar specific (DateRangePicker).
        public int $numberOfMonths = 1,
        public ?string $weekdayFormat = null,
        public bool $fixedWeeks = false,
        // First day of week for the WeekPicker (0=Sun … 6=Sat).
        public ?int $weekStartsOn = null,
        // AddressPicker — which sub-fields to show, in order.
        public ?array $addressFields = null,
        // RichEditor — which editor driver to render (basic|tiptap|markdown).
        public ?string $editor = null,
        // NumberField / Slider — {min,max,step,format,currency,decimals,locale}.
        public ?array $numberConfig = null,
        // Rating — {max,allowHalf}.
        public ?array $ratingConfig = null,
        // PinInput — {length,mask,otp,type}.
        public ?array $pinConfig = null,
        // SlugInput — {from,separator}.
        public ?array $slugConfig = null,
        // SignaturePad — {penColor,backgroundColor,height}.
        public ?array $signatureConfig = null,
        // PhoneInput — {defaultCountry, countries:[{code,name,dial}]}.
        public ?array $phoneConfig = null,
    ) {}
}
