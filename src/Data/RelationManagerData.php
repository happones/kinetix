<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class RelationManagerData extends Data
{
    public function __construct(
        public string $title,
        public string $relationship,
        public TableData $table,
        /** Badge next to the title / on the tab (e.g. a record count). */
        public int|string|null $badge = null,
        /** Kinetix status color for the badge (primary, gray, success…). */
        public ?string $badgeColor = null,
        /** Signed attach/detach descriptor (BelongsToMany managers only). */
        public ?string $descriptor = null,
        /**
         * Serialized pivot form the attach modal renders below the record
         * picker (AttachAction::form()).
         *
         * @var array<string, mixed>|null
         */
        public ?array $attachForm = null,
    ) {}
}
