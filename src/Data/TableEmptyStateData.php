<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class TableEmptyStateData extends Data
{
    public function __construct(
        public ?string $heading = null,
        public ?string $description = null,
        /** Lucide icon name (shared resolveIcon map). */
        public ?string $icon = null,
        /**
         * CTA actions rendered under the text (e.g. a Create modal action).
         *
         * @var array<int, ActionData>
         */
        public array $actions = [],
    ) {}
}
