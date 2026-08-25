<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ImportSettingsData extends Data
{
    /**
     * How the import dialog is allowed to present itself, and how much of the
     * file it shows. Resolved per importer (class overrides win over the
     * `kinetix.imports` config) and sent to the frontend both with the
     * `open-importer` event and with every preview payload.
     *
     * @param bool        $hasPreview          Whether the sample-data table renders at all
     * @param int         $previewRows         Data rows sampled (also the reader's row ceiling)
     * @param int         $previewColumns      Source columns shown before the rest collapse (0 = no cap)
     * @param string      $layout              'auto' | 'modal' | 'fullscreen' | 'sheet'
     * @param int         $fullscreenThreshold Column count above which 'auto' goes full screen
     * @param int         $maxUploadSize       Upload ceiling in kilobytes
     * @param string|null $template            Template filename, or null when the importer offers none
     */
    public function __construct(
        public bool $hasPreview,
        public int $previewRows,
        public int $previewColumns,
        public string $layout,
        public int $fullscreenThreshold,
        public int $maxUploadSize,
        public ?string $template,
    ) {}
}
