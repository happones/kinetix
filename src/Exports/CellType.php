<?php

declare(strict_types=1);

namespace Happones\Kinetix\Exports;

/**
 * What an export cell is, so a spreadsheet reads it as such instead of
 * guessing from its text ({@see ExportColumn::date()}, {@see ExportColumn::numeric()},
 * {@see ExportColumn::text()}).
 */
enum CellType: string
{
    case Text   = 'text';
    case Number = 'number';
    case Date   = 'date';
}
