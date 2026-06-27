<?php

declare(strict_types=1);

namespace Happones\Kinetix\Reports;

use Happones\Kinetix\Exports\Exporter;
use Happones\Kinetix\Exports\FileWriter;
use Illuminate\Support\Facades\Mail;

/**
 * Runs a {@see ScheduledReport}: builds its Exporter's output to a temp file
 * (reusing the export FileWriter pipeline) and emails it to the recipients.
 */
class ReportRunner
{
    /**
     * Generate the report file and mail it. Returns false (no send) when the
     * report has no recipients.
     */
    public function run(ScheduledReport $report): bool
    {
        if ($report->getRecipients() === []) {
            return false;
        }

        $exporterClass = $report->getExporter();
        /** @var Exporter $exporter */
        $exporter = (new $exporterClass)->withParameters($report->getParameters());

        [$path, $filename] = $this->generate($exporter);

        try {
            Mail::to($report->getRecipients())->send(
                new ScheduledReportMail($report->getSubject(), $path, $filename),
            );
        } finally {
            @unlink($path);
        }

        return true;
    }

    /**
     * Write the exporter's rows to a temp file. Returns [absolutePath, fileName].
     *
     * @return array{0: string, 1: string}
     */
    protected function generate(Exporter $exporter): array
    {
        $format   = $exporter->format();
        $tempPath = (string) tempnam(sys_get_temp_dir(), 'kinetix_report_');

        $writer = new FileWriter($tempPath, $format);
        $writer->writeRow($exporter->headings());

        $exporter->resolveExportQuery()->chunk(
            $exporter->chunkSize(),
            function ($records) use ($writer, $exporter): void {
                foreach ($records as $record) {
                    $writer->writeRow($exporter->mapRecord($record));
                }
            },
        );

        $summaryRow = $exporter->summaryRow($exporter->resolveExportQuery());
        if ($summaryRow !== null) {
            $writer->writeRow($summaryRow);
        }

        $writer->close();

        return [$tempPath, $exporter->fileName().'.'.$format];
    }
}
