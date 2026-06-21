<?php

declare(strict_types=1);

namespace Happones\Kinetix\Exports;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv as CsvWriter;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use RuntimeException;

/**
 * Streams CSV rows directly to disk; buffers rows for Excel and writes on close.
 */
class FileWriter
{
    /**
     * @var resource|null
     */
    private $handle = null;

    /**
     * @var array<int, array<int, mixed>>
     */
    private array $buffer = [];

    public function __construct(
        private string $absolutePath,
        private string $format,
    ) {
        if ($this->format === 'csv') {
            $handle = fopen($this->absolutePath, 'w');

            if ($handle === false) {
                throw new RuntimeException("Unable to open export file: {$this->absolutePath}");
            }

            $this->handle = $handle;
        }
    }

    /**
     * @param array<int, mixed> $row
     */
    public function writeRow(array $row): void
    {
        if ($this->format === 'csv') {
            // Empty escape keeps RFC-4180 quoting and satisfies PHP 8.4+ deprecation.
            fputcsv($this->handle, $row, ',', '"', '');

            return;
        }

        $this->buffer[] = $row;
    }

    public function close(): void
    {
        if ($this->format === 'csv') {
            if ($this->handle !== null) {
                fclose($this->handle);
                $this->handle = null;
            }

            return;
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($this->buffer, null, 'A1', true);

        $writer = $this->format === 'xlsx'
            ? new XlsxWriter($spreadsheet)
            : new CsvWriter($spreadsheet);

        $writer->save($this->absolutePath);
        $spreadsheet->disconnectWorksheets();
        $this->buffer = [];
    }
}
