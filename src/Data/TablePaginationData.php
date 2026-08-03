<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The paginator state shipped to the table footer.
 *
 * `total` and `lastPage` are **nullable**: a simple-paginated table has neither,
 * because skipping the `COUNT(*)` is the entire point of that mode — and the
 * count is exactly what gets expensive on a large table. Read `hasMore` for
 * "is there a next page" rather than comparing against `lastPage`.
 */
#[TypeScript]
class TablePaginationData extends Data
{
    public function __construct(
        public int $perPage,
        public int $currentPage,
        public bool $hasMore,
        public ?int $total = null,
        public ?int $lastPage = null,
        public ?int $from = null,
        public ?int $to = null,
    ) {}
}
