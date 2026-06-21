<?php

declare(strict_types=1);

namespace Happones\Kinetix\Imports;

use Happones\Kinetix\Data\ImportOptionsData;
use PhpOffice\PhpSpreadsheet\IOFactory;

class FileReader
{
    /**
     * Read a tabular file into headers and rows.
     *
     * @return array{headers: array<int, string>, rows: array<int, array<int, string|null>>}
     */
    public static function read(string $path, ImportOptionsData $options, ?int $limit = null): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, ['csv', 'txt', 'tsv'], true)) {
            return static::readCsv($path, $options, $limit);
        }

        return static::readSpreadsheet($path, $options, $limit);
    }

    /**
     * Count the number of data rows (excluding skipped lines and the header).
     */
    public static function countRows(string $path, ImportOptionsData $options): int
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $overhead = $options->skipLines + ($options->hasHeader ? 1 : 0);

        if (in_array($extension, ['csv', 'txt', 'tsv'], true)) {
            $lines = 0;
            $handle = fopen($path, 'r');

            if ($handle === false) {
                return 0;
            }

            while (fgets($handle) !== false) {
                $lines++;
            }

            fclose($handle);

            return max(0, $lines - $overhead);
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $highestRow = $spreadsheet->getActiveSheet()->getHighestDataRow();
        $spreadsheet->disconnectWorksheets();

        return max(0, $highestRow - $overhead);
    }

    /**
     * @return array{headers: array<int, string>, rows: array<int, array<int, string|null>>}
     */
    protected static function readCsv(string $path, ImportOptionsData $options, ?int $limit): array
    {
        $rows = [];
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return ['headers' => [], 'rows' => []];
        }

        $skipped = 0;
        while ($options->skipLines > $skipped && fgets($handle) !== false) {
            $skipped++;
        }

        $maxRows = $limit !== null ? $limit + ($options->hasHeader ? 1 : 0) : null;

        // Pass an empty escape so parsing follows RFC 4180 (doubled quotes) rather
        // than the legacy backslash behaviour, and to satisfy PHP 8.4+ which
        // deprecates calling fgetcsv() without an explicit $escape argument.
        while (($record = fgetcsv($handle, 0, $options->delimiter, $options->enclosure, '')) !== false) {
            // Skip fully empty lines produced by trailing newlines.
            if ($record === [null]) {
                continue;
            }

            $rows[] = array_map(static fn ($value) => $value === null ? null : (string) $value, $record);

            if ($maxRows !== null && count($rows) >= $maxRows) {
                break;
            }
        }

        fclose($handle);

        return static::splitHeader($rows, $options);
    }

    /**
     * @return array{headers: array<int, string>, rows: array<int, array<int, string|null>>}
     */
    protected static function readSpreadsheet(string $path, ImportOptionsData $options, ?int $limit): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $allRows = $sheet->toArray(null, true, false, false);
        $spreadsheet->disconnectWorksheets();

        if ($options->skipLines > 0) {
            $allRows = array_slice($allRows, $options->skipLines);
        }

        $rows = [];
        $maxRows = $limit !== null ? $limit + ($options->hasHeader ? 1 : 0) : null;

        foreach ($allRows as $record) {
            $rows[] = array_map(static fn ($value) => $value === null ? null : (string) $value, $record);

            if ($maxRows !== null && count($rows) >= $maxRows) {
                break;
            }
        }

        return static::splitHeader($rows, $options);
    }

    /**
     * Split raw rows into headers + data, generating headers when the file has none.
     *
     * @param array<int, array<int, string|null>> $rows
     * @return array{headers: array<int, string>, rows: array<int, array<int, string|null>>}
     */
    protected static function splitHeader(array $rows, ImportOptionsData $options): array
    {
        if ($rows === []) {
            return ['headers' => [], 'rows' => []];
        }

        if ($options->hasHeader) {
            $headerRow = array_shift($rows);
            $headers = array_map(static fn ($value) => (string) $value, $headerRow);

            return ['headers' => $headers, 'rows' => array_values($rows)];
        }

        $columnCount = count($rows[0]);
        $headers = [];
        for ($i = 0; $i < $columnCount; $i++) {
            $headers[] = 'Column '.($i + 1);
        }

        return ['headers' => $headers, 'rows' => $rows];
    }
}
