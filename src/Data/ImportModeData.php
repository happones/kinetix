<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Happones\Kinetix\Imports\ImportMode;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ImportModeData extends Data
{
    /**
     * One choice of the dialog's "existing records" picker.
     *
     * @param string $value       An {@see ImportMode} value
     * @param string $description What the mode does to the records already there
     * @param bool   $destructive Whether it deactivates records (the dialog warns)
     */
    public function __construct(
        public string $value,
        public string $label,
        public string $description,
        public bool $destructive,
    ) {}
}
