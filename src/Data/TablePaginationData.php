<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The paginator state shipped to the table footer, across three modes.
 *
 * Which fields are populated is the mode:
 *
 * | Field         | `paginated()` | `simplePaginated()` | `cursorPaginated()` |
 * |---------------|---------------|---------------------|---------------------|
 * | `total`       | ✅            | null (no COUNT)     | null                |
 * | `lastPage`    | ✅            | null                | null                |
 * | `currentPage` | ✅            | ✅                  | null (no pages)     |
 * | `from` / `to` | ✅            | ✅                  | null (no offsets)   |
 * | `nextCursor`  | null          | null                | ✅ / null at end    |
 * | `hasMore`     | ✅            | ✅                  | ✅                  |
 *
 * `hasMore` is the one signal present in every mode — read it instead of
 * comparing `currentPage` against `lastPage`.
 */
#[TypeScript]
class TablePaginationData extends Data
{
    public function __construct(
        public int $perPage,
        public bool $hasMore,
        public ?int $currentPage = null,
        public ?int $total = null,
        public ?int $lastPage = null,
        public ?int $from = null,
        public ?int $to = null,
        /** Opaque seek positions; only cursor mode sets them. */
        public ?string $nextCursor = null,
        public ?string $prevCursor = null,
        /** Cursor mode has no page number, so "can I go back" is explicit. */
        public ?bool $onFirstPage = null,
    ) {}
}
