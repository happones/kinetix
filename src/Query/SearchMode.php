<?php

declare(strict_types=1);

namespace Happones\Kinetix\Query;

/**
 * How a search term is matched against a column.
 *
 * - `Contains` (the default): `LIKE '%term%'`. Finds the term anywhere, and
 *   cannot use a B-tree index: every row is read.
 * - `Prefix`: `LIKE 'term%'`. Matches the start of the value and can use a
 *   B-tree index (`text_pattern_ops` on PostgreSQL).
 * - `Exact`: `column = term`, with a type guard: a term that cannot be a value
 *   of the column (letters against an integer key, a malformed UUID) skips the
 *   column instead of erroring.
 * - `FullText`: the engine's full-text search (natural language on MySQL and
 *   MariaDB, `plainto_tsquery` on PostgreSQL), which needs a FULLTEXT / GIN
 *   index. SQLite and SQL Server fall back to `Contains`.
 *
 * The mode changes only how the term is compared: tenant and scope constraints
 * of the query it is applied to are untouched.
 */
enum SearchMode: string
{
    case Contains = 'contains';
    case Prefix   = 'prefix';
    case Exact    = 'exact';
    case FullText = 'full_text';
}
