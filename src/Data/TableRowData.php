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
        /** Open `recordUrl` in a new tab (Table::openRecordUrlInNewTab() or the inferred action's own flag). */
        public bool $recordUrlInNewTab = false,
        /** Name of the row action a click runs when there is no `recordUrl` — resolved from `actions`. */
        public ?string $recordAction = null,
        /**
         * Stable key of the group this row belongs to when a Table::group is
         * active (the frontend buckets contiguous rows sharing it under one
         * header, and remembers collapsed state by it). Null when ungrouped or
         * the record has no group value.
         */
        public string|int|null $groupKey = null,
        /** Human-readable header title for this row's group. Null when ungrouped. */
        public ?string $groupLabel = null,
    ) {}
}
