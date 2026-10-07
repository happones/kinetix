<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Happones\Kinetix\Tables\Group;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row-grouping definition serialized to the frontend (see
 * {@see Group}).
 *
 * `column` doubles as the group's stable identity: it is the value
 * {@see TableData::$defaultGroup} names and the frontend matches each row's
 * `groupKey` under. The per-row key/title themselves are computed server-side
 * and shipped on {@see TableRowData}, so the frontend only lays groups out — it
 * never recomputes membership.
 */
#[TypeScript]
class GroupData extends Data
{
    public function __construct(
        public string $column,
        public string $label,
        public bool $collapsible,
    ) {}
}
