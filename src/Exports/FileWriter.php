<?php

declare(strict_types=1);

namespace Happones\Kinetix\Exports;

use Dompdf\Dompdf;
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

        if ($this->format === 'pdf') {
            $this->writePdf();

            return;
        }

        $spreadsheet = new Spreadsheet;
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->fromArray($this->buffer, null, 'A1', true);

        $writer = $this->format === 'xlsx'
            ? new XlsxWriter($spreadsheet)
            : new CsvWriter($spreadsheet);

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
