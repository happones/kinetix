<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class TableRowData extends Data
{
    /**
     * @param array<string, mixed>                                      $values
     * @param array<string, string|null>                                $icons
     * @param array<string, string>                                     $iconColors
     * @param array<string, string>                                     $badgeColors
     * @param array<string, int|float|null>                             $progress
     * @param array<string, string>                                     $progressColors
     * @param array<string, array<string, mixed>>                       $viewProps
     * @param array<string, array{text: string|null, position: string}> $descriptions
     * @param array<int, ActionData>                                    $actions
     * @param array<string, string|null>                                $urls
     */
    public function __construct(
        public mixed $id,
        public array $values,
        public array $icons,
        public array $iconColors,
        public array $badgeColors,
        public array $descriptions,
        public ?string $recordUrl = null,
        public array $actions = [],
        public array $progress = [],
        public array $progressColors = [],
        public array $viewProps = [],
        public array $urls = [],
    ) {}
}
