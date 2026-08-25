<?php

declare(strict_types=1);

namespace Happones\Kinetix\Imports;

use Generator;
use Happones\Kinetix\Data\ImportOptionsData;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Reads tabular import files (csv/txt/tsv, xls/xlsx) WITHOUT ever holding the
 * whole file in memory.
 *
 * Everything here is bounded on purpose, because an import file is allowed to
 * be enormous (a million rows is a supported case):
 *
 * - {@see stream()} yields one row at a time, so the queued job's memory is
 *   bounded by its chunk size instead of by the row count.
 * - {@see read()} takes a limit and STOPS there — a ten-row preview parses ten
 *   rows, whatever the file's size.
 * - {@see countRows()} counts without parsing: newline blocks for CSV, the
 *   workbook's own row metadata for spreadsheets.
 *
 * Spreadsheets are read one WINDOW of rows at a time ({@see RowWindowFilter}),
 * re-opening the file per window, since PhpSpreadsheet has no streaming reader.
 * The FIRST worksheet is the one read.
 */
class FileReader
{
    /**
     * Extensions parsed as delimited text rather than as a workbook.
     */
    protected const TEXT_EXTENSIONS = ['csv', 'txt', 'tsv'];

    /**
     * Read a tabular file into headers and rows.
     *
     * `$limit` caps the DATA rows returned and doubles as a read ceiling: the
     * reader stops once it has that many, so previewing is O(limit), not
     * O(file). Null reads every row — only do that for a file you know is
     * small; the queued import uses {@see stream()} instead.
     *
     * @return array{headers: array<int, string>, rows: array<int, array<int, string|null>>}
     */
    public static function read(string $path, ImportOptionsData $options, ?int $limit = null): array
    {
        $headers = static::headers($path, $options);
        $rows    = [];

        if ($limit !== null && $limit <= 0) {
            return ['headers' => $headers, 'rows' => []];
        }

        foreach (static::stream($path, $options) as $row) {
            $rows[] = $row;

            if ($limit !== null && count($rows) >= $limit) {
                break;
            }
        }

        // A header-less file names its columns after the widest row seen, so a
        // preview never drops cells that only later rows carry.
        if (! $options->hasHeader) {
            $headers = static::generatedHeaders(static::widestRow($rows, count($headers)));
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * The file's header row — the column names the mapping is built against.
     *
     * Reads only as far as it needs to: for a header-less file it reads the
     * first data row purely to learn how many columns there are, and names
     * them `Column 1..N`.
     *
     * @return array<int, string>
     */
    public static function headers(string $path, ImportOptionsData $options): array
    {
        $first = static::firstRecord($path, $options);

        if ($first === null) {
            return [];
        }

        if ($options->hasHeader) {
            return array_map(static fn ($value) => (string) $value, $first);
        }

        return static::generatedHeaders(count($first));
    }

    /**
     * Stream the file's DATA rows (header and skipped lines already consumed),
     * one row at a time.
     *
     * This is what makes a million-row import possible: the caller decides how
     * many rows to hold at once, and the reader never builds the full array.
     *
     * @return Generator<int, array<int, string|null>>
     */
    public static function stream(string $path, ImportOptionsData $options): Generator
    {
        if (static::isText($path)) {
            yield from static::streamCsv($path, $options);

            return;
        }

        yield from static::streamSpreadsheet($path, $options);
    }

    /**
     * Count the data rows (excluding skipped lines and the header).
     *
     * Deliberately cheap rather than exact: CSV rows are counted by scanning
     * for newlines in blocks (a field containing a literal newline therefore
     * counts more than once), and spreadsheets report the row count the
     * workbook itself records. Both avoid parsing the file, which is the point
     * — this number only labels the dialog ("1,204,882 rows detected"), it
     * never drives the import.
     */
    public static function countRows(string $path, ImportOptionsData $options): int
    {
        $overhead = $options->skipLines + ($options->hasHeader ? 1 : 0);

        if (! static::isText($path)) {
            return max(0, static::spreadsheetInfo($path)['totalRows'] - $overhead);
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return 0;
        }

        $lines    = 0;
        $lastByte = '';

        while (($block = fread($handle, 1048576)) !== false && $block !== '') {
            $lines += substr_count($block, "\n");
            $lastByte = substr($block, -1);
        }

        fclose($handle);

        // A final row with no trailing newline still counts.
        if ($lastByte !== '' && $lastByte !== "\n") {
            $lines++;
        }

        return max(0, $lines - $overhead);
    }

    /**
     * @return Generator<int, array<int, string|null>>
     */
    protected static function streamCsv(string $path, ImportOptionsData $options): Generator
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return;
        }

        try {
            $skipped = 0;
            while ($options->skipLines > $skipped && fgets($handle) !== false) {
                $skipped++;
            }

            $headerConsumed = ! $options->hasHeader;

            // Pass an empty escape so parsing follows RFC 4180 (doubled quotes)
            // rather than the legacy backslash behaviour, and to satisfy PHP
            // 8.4+ which deprecates fgetcsv() without an explicit $escape.
            while (($record = fgetcsv($handle, 0, $options->delimiter, $options->enclosure, '')) !== false) {
                // Skip fully empty lines produced by trailing newlines.
                if ($record === [null]) {
                    continue;
                }

                if (! $headerConsumed) {
                    $headerConsumed = true;

                    continue;
                }

                yield static::normalizeRecord($record);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return Generator<int, array<int, string|null>>
     */
    protected static function streamSpreadsheet(string $path, ImportOptionsData $options): Generator
    {
        $info       = static::spreadsheetInfo($path);
        $lastRow    = $info['totalRows'];
        $lastColumn = $info['lastColumnLetter'];

        if ($lastRow < 1 || $lastColumn === '') {
            return;
        }

        // 1-indexed sheet row the data starts on.
        $firstDataRow = $options->skipLines + ($options->hasHeader ? 1 : 0) + 1;
        $window       = max(1, (int) config('kinetix.imports.spreadsheet_chunk_size', 2000));

        for ($first = $firstDataRow; $first <= $lastRow; $first += $window) {
            $last = min($lastRow, $first + $window - 1);

            foreach (static::spreadsheetWindow($path, $info['worksheetName'], $lastColumn, $first, $last) as $record) {
                yield static::normalizeRecord($record);
            }
        }
    }

    /**
     * Load ONE window of spreadsheet rows and return them as arrays.
     *
     * @return array<int, array<int, mixed>>
     */
    protected static function spreadsheetWindow(
        string $path,
        string $worksheetName,
        string $lastColumn,
        int $firstRow,
        int $lastRow,
    ): array {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setReadFilter(new RowWindowFilter($firstRow, $lastRow));

        // Only the sheet being read is loaded, so a workbook with other large
        // sheets doesn't pay for them on every window.
        $reader->setLoadSheetsOnly($worksheetName);

        $spreadsheet = $reader->load($path);

        try {
            $rows = $spreadsheet->getActiveSheet()->rangeToArray(
                "A{$firstRow}:{$lastColumn}{$lastRow}",
                null,
                true,
                false,
                false,
            );
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }

        return $rows;
    }

    /**
     * The workbook's own row/column metadata, read WITHOUT loading any cells.
     *
     * @return array{worksheetName: string, totalRows: int, lastColumnLetter: string}
     */
    protected static function spreadsheetInfo(string $path): array
    {
        $sheets = IOFactory::createReaderForFile($path)->listWorksheetInfo($path);
        $sheet  = $sheets[0] ?? null;

        if ($sheet === null) {
            return ['worksheetName' => '', 'totalRows' => 0, 'lastColumnLetter' => ''];
        }

        return [
            'worksheetName'    => (string) $sheet['worksheetName'],
            'totalRows'        => (int) $sheet['totalRows'],
            'lastColumnLetter' => (string) $sheet['lastColumnLetter'],
        ];
    }

    /**
     * The first record of the file after the skipped lines — the header row, or
     * the first data row when the file has no header.
     *
     * @return array<int, mixed>|null
     */
    protected static function firstRecord(string $path, ImportOptionsData $options): ?array
    {
        if (! static::isText($path)) {
            $info = static::spreadsheetInfo($path);
            $row  = $options->skipLines + 1;

            if ($info['totalRows'] < $row || $info['lastColumnLetter'] === '') {
                return null;
            }

            $rows = static::spreadsheetWindow($path, $info['worksheetName'], $info['lastColumnLetter'], $row, $row);

            return $rows[0] ?? null;
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            return null;
        }

        try {
            $skipped = 0;
            while ($options->skipLines > $skipped && fgets($handle) !== false) {
                $skipped++;
            }

            while (($record = fgetcsv($handle, 0, $options->delimiter, $options->enclosure, '')) !== false) {
                if ($record === [null]) {
                    continue;
                }

                return $record;
            }
        } finally {
            fclose($handle);
        }

        return null;
    }

    /**
     * @param  array<int, mixed>       $record
     * @return array<int, string|null>
     */
    protected static function normalizeRecord(array $record): array
    {
        return array_map(static fn ($value) => $value === null ? null : (string) $value, array_values($record));
    }

    /**
     * @return array<int, string>
     */
    protected static function generatedHeaders(int $count): array
    {
        $headers = [];

        for ($i = 0; $i < $count; $i++) {
            $headers[] = 'Column '.($i + 1);
        }

        return $headers;
    }

    /**
     * @param array<int, array<int, string|null>> $rows
     */
    protected static function widestRow(array $rows, int $minimum): int
    {
        $widest = $minimum;

        foreach ($rows as $row) {
            $widest = max($widest, count($row));
        }

        return $widest;
    }

    protected static function isText(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), static::TEXT_EXTENSIONS, true);
    }
}
