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
        /** Null while a lazy manager is deferred — only the tab stub shipped. */
        public ?TableData $table,
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
        /**
         * A lazy manager whose table hasn't been loaded yet: only the tab
         * stub (title/badge) shipped. The frontend requests the full payload
         * by revisiting with `?relation={relationship}`.
         */
        public bool $deferred = false,
        /** Translated group label — managers sharing it render as ONE tab. */
        public ?string $group = null,
        /** The group's stable `?relation=` key (raw label, slugged). */
        public ?string $groupKey = null,
        /** The section shows a collapse toggle wherever its heading renders. */
        public bool $collapsible = false,
        /** Start collapsed. */
        public bool $collapsed = false,
    ) {}
}
