<?php

declare(strict_types=1);

namespace Happones\Kinetix\Imports\Jobs;

use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Data\ImportOptionsData;
use Happones\Kinetix\Exports\DownloadToken;
use Happones\Kinetix\Exports\FileWriter;
use Happones\Kinetix\Imports\FileReader;
use Happones\Kinetix\Imports\Importer;
use Happones\Kinetix\Notifications\Notification;
use Happones\Kinetix\Support\KinetixDisk;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\File;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class ImportProcessor implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * The open failed-rows CSV writer (null when the import has no recipient,
     * or once the log has been published).
     */
    protected ?FileWriter $failureLog = null;

    protected ?string $failureLogPath = null;

    protected int $failureLogRows = 0;

    /**
     * @param class-string<Importer>  $importerClass
     * @param array<string, mixed>    $options
     * @param array<string, int|null> $mapping        column name => source header index
     * @param class-string|null       $recipientClass
     * @param array<string, mixed>    $context        request context captured by Importer::context()
     * @param int|string|null         $teamKey        captured at dispatch (the worker has no
     *                                                request); stamps the notification when
     *                                                notifications are team-scoped
     */
    public function __construct(
        protected string $importerClass,
        protected string $path,
        protected array $options,
        protected array $mapping,
        protected ?string $recipientClass = null,
        protected int|string|null $recipientId = null,
        protected array $context = [],
        protected int|string|null $teamKey = null,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /**
     * Runs once after $tries is exhausted. A whole-file failure (unreadable file,
     * DB outage) would otherwise leave the user staring at an import that never
     * reports back.
     */
    public function failed(Throwable $e): void
    {
        if ($this->recipientClass === null || $this->recipientId === null) {
            return;
        }

        $recipient = $this->recipientClass::find($this->recipientId);

        if ($recipient === null) {
            return;
        }

        /** @var Importer $importer */
        $importer = (new $this->importerClass)->withContext($this->context);

        $notification = Notification::make()
            ->title($importer->getFailedNotificationTitle())
            ->body($importer->getFailedNotificationBody())
            ->team($this->teamKey)
            ->danger();

        if (Notification::shouldBroadcast()) {
            $notification->broadcast($recipient);

            return;
        }

        $notification->sendToDatabase($recipient);
    }

    public function handle(): void
    {
        /** @var Importer $importer */
        $importer = (new $this->importerClass)->withContext($this->context);
        $options  = ImportOptionsData::from($this->options);

        $disk                 = KinetixDisk::privateName();
        [$localPath, $isTemp] = KinetixDisk::localReadablePath($disk, $this->path);

        $imported = 0;
        $failed   = 0;

        // Row number as the user sees it in their file, so a reported failure can
        // actually be found and fixed. A header row occupies line 1.
        $rowNumber = $options->hasHeader ? 1 : 0;

        /** @var array<int, string> $failures */
        $failures = [];

        try {
            $headers = FileReader::headers($localPath, $options);
            $rules   = $this->mappedRules($importer);

            // Failed rows are written out as they happen instead of collected:
            // a wholly mismatched million-row file would otherwise hold a
            // million rows in worker memory on its way to the CSV.
            $this->openFailureLog($headers);

            $chunkSize = max(1, $importer->chunkSize());
            $buffer    = [];

            // The file is STREAMED, one row at a time — memory is bounded by
            // the chunk size, never by the row count, so a million-row import
            // costs the same as a thousand-row one.
            foreach (FileReader::stream($localPath, $options) as $row) {
                $buffer[] = $row;

                if (count($buffer) < $chunkSize) {
                    continue;
                }

                $this->processChunk($buffer, $importer, $rules, $imported, $failed, $failures, $rowNumber);
                $buffer = [];
            }

            if ($buffer !== []) {
                $this->processChunk($buffer, $importer, $rules, $imported, $failed, $failures, $rowNumber);
            }
        } finally {
            KinetixDisk::discardTemp($localPath, $isTemp);
        }

        Storage::disk($disk)->delete($this->path);

        $this->notify($importer, $imported, $failed, $failures, $this->publishFailureLog());
    }

    /**
     * Import one buffered chunk of rows inside a single transaction.
     *
     * @param array<int, array<int, string|null>> $chunk
     * @param array<string, array<int, mixed>>    $rules
     * @param array<int, string>                  $failures
     */
    protected function processChunk(
        array $chunk,
        Importer $importer,
        array $rules,
        int &$imported,
        int &$failed,
        array &$failures,
        int &$rowNumber,
    ): void {
        DB::transaction(function () use ($chunk, $importer, $rules, &$imported, &$failed, &$failures, &$rowNumber): void {
            foreach ($chunk as $row) {
                $rowNumber++;
                $data = $this->mapRow($row);

                if ($rules !== []) {
                    $validator = Validator::make($data, $rules);

                    if ($validator->fails()) {
                        $failed++;
                        $reason = (string) $validator->errors()->first();
                        $this->recordFailure($failures, $rowNumber, $reason);
                        $this->logFailedRow($rowNumber, $row, $reason);

                        continue;
                    }
                }

                try {
                    $importer->importRow($data);
                    $imported++;
                } catch (Throwable $e) {
                    $failed++;
                    $this->recordFailure($failures, $rowNumber, $e->getMessage());
                    $this->logFailedRow($rowNumber, $row, $e->getMessage());
                }
            }
        });
    }

    /**
     * Open the failed-rows CSV, so every skipped row can be written out the
     * moment it fails rather than accumulated in memory. No-op when the import
     * has no recipient (nobody could download the result).
     *
     * @param array<int, string> $headers
     */
    protected function openFailureLog(array $headers): void
    {
        if ($this->recipientClass === null || $this->recipientId === null) {
            return;
        }

        $this->failureLogPath = (string) tempnam(sys_get_temp_dir(), 'kinetix_import_failures_');
        $this->failureLog     = new FileWriter($this->failureLogPath, 'csv');

        if ($headers !== []) {
            $this->failureLog->writeRow([
                (string) __('kinetix.import_failures_row_heading'),
                ...$headers,
                (string) __('kinetix.import_failures_error_heading'),
            ]);
        }
    }

    /**
     * Append one skipped row (with its reason) to the failed-rows CSV.
     *
     * @param array<int, string|null> $row
     */
    protected function logFailedRow(int $rowNumber, array $row, string $reason): void
    {
        if ($this->failureLog === null) {
            return;
        }

        $this->failureLogRows++;
        $this->failureLog->writeRow([$rowNumber, ...array_values($row), $reason]);
    }

    /**
     * Close the failed-rows CSV and, when anything failed, store it behind the
     * same signed, user-bound, expiring download token exports use — so beyond
     * the 10 rows quoted in the notification body, nothing is lost.
     */
    protected function publishFailureLog(): ?string
    {
        if ($this->failureLog === null || $this->failureLogPath === null) {
            return null;
        }

        $this->failureLog->close();
        $this->failureLog = null;

        $localPath            = $this->failureLogPath;
        $this->failureLogPath = null;

        if ($this->failureLogRows === 0) {
            @unlink($localPath);

            return null;
        }

        $disk = KinetixDisk::privateName();
        // Stored under kinetix-exports so the existing token-guarded download
        // endpoint (constrained to that directory) can serve it.
        $directory  = 'kinetix-exports';
        $storedName = Str::uuid()->toString().'.csv';

        Storage::disk($disk)->putFileAs($directory, new File($localPath), $storedName);
        @unlink($localPath);

        $downloadName = str(class_basename($this->importerClass))->kebab()->append('-failed-rows.csv')->toString();
        $token        = DownloadToken::mint($disk, $directory.'/'.$storedName, $downloadName, $this->recipientId);

        return route('kinetix.exports.download', ['token' => $token]);
    }

    /**
     * Translate a raw source row into a column-name keyed array using the mapping.
     *
     * @param  array<int, string|null> $row
     * @return array<string, mixed>
     */
    protected function mapRow(array $row): array
    {
        $data = [];

        foreach ($this->mapping as $column => $headerIndex) {
            if ($headerIndex === null) {
                continue;
            }

            $data[$column] = $row[$headerIndex] ?? null;
        }

        return $data;
    }

    /**
     * Build validation rules only for mapped columns that declare them.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function mappedRules(Importer $importer): array
    {
        $rules = [];

        foreach ($importer::getColumns() as $column) {
            $name = $column->getName();
            // isset() excludes both unmapped (absent) and explicitly-null columns.
            $isMapped = isset($this->mapping[$name]);

            if ($isMapped && $column->getRules() !== []) {
                $rules[$name] = $column->getRules();
            }
        }

        return $rules;
    }

    /**
     * Keep the first few failures, so the notification can say WHICH rows failed
     * and why instead of only how many. Bounded, because a wholly mismatched file
     * would otherwise put thousands of messages in a notification payload.
     *
     * @param array<int, string> $failures
     */
    protected function recordFailure(array &$failures, int $rowNumber, string $reason): void
    {
        if (count($failures) >= 10) {
            return;
        }

        $failures[] = (string) __('kinetix.import_failed_row', [
            'row'    => $rowNumber,
            'reason' => $reason,
        ]);
    }

    /**
     * Notify the user that the import finished.
     *
     * @param array<int, string> $failures
     */
    protected function notify(Importer $importer, int $imported, int $failed, array $failures = [], ?string $failedRowsUrl = null): void
    {
        if ($this->recipientClass === null || $this->recipientId === null) {
            return;
        }

        $recipient = $this->recipientClass::find($this->recipientId);

        if ($recipient === null) {
            return;
        }

        $body = $importer->getCompletedNotificationBody($imported, $failed);

        if ($failures !== []) {
            $body .= "\n".implode("\n", $failures);

            if ($failed > count($failures)) {
                $body .= "\n".__('kinetix.import_failed_more', ['count' => $failed - count($failures)]);
            }
        }

        $notification = Notification::make()
            ->title($importer->getCompletedNotificationTitle($imported, $failed))
            ->body($body)
            ->team($this->teamKey)
            ->status($failed > 0 ? 'warning' : 'success');

        if ($failedRowsUrl !== null) {
            $notification->actions([
                Action::make('downloadFailedRows')
                    ->label((string) __('kinetix.download_failed_rows'))
                    ->icon('download')
                    ->color('gray')
                    ->button()
                    ->url($failedRowsUrl, true),
            ]);
        }

        if (Notification::shouldBroadcast()) {
            $notification->broadcast($recipient);

            return;
        }

        $notification->sendToDatabase($recipient);
    }
}
