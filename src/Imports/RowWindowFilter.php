<?php

declare(strict_types=1);

namespace Happones\Kinetix\Imports;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * Restricts a spreadsheet read to one window of rows.
 *
 * A spreadsheet has no streaming reader: loading it materializes every cell as
 * an object, so a million-row workbook cannot be read in one pass. Reading it
 * in windows — re-opening the file per window with this filter — keeps memory
 * proportional to the window, not to the file.
 */
class RowWindowFilter implements IReadFilter
{
    public function __construct(
        protected int $firstRow,
        protected int $lastRow,
    ) {}

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        return $row >= $this->firstRow && $row <= $this->lastRow;
    }
}
