<?php

declare(strict_types=1);

namespace Happones\Kinetix\Imports\Jobs;

use Happones\Kinetix\Data\ImportOptionsData;
use Happones\Kinetix\Imports\FileReader;
use Happones\Kinetix\Imports\Importer;
use Happones\Kinetix\Notifications\Notification;
use Happones\Kinetix\Support\KinetixDisk;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ImportProcessor implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @param class-string<Importer>  $importerClass
     * @param array<string, mixed>    $options
     * @param array<string, int|null> $mapping        column name => source header index
     * @param class-string|null       $recipientClass
     * @param array<string, mixed>    $context        request context captured by Importer::context()
     */
    public function __construct(
        protected string $importerClass,
        protected string $path,
        protected array $options,
        protected array $mapping,
        protected ?string $recipientClass = null,
        protected int|string|null $recipientId = null,
        protected array $context = [],
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

        $notification = Notification::make()
            ->title((string) __('kinetix.import_failed'))
            ->body((string) __('kinetix.import_failed_body'))
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

        try {
            $parsed = FileReader::read($localPath, $options);
        } finally {
            KinetixDisk::discardTemp($localPath, $isTemp);
        }

        $rows = $parsed['rows'];

        $rules = $this->mappedRules($importer);

        $imported = 0;
        $failed   = 0;

        // Row number as the user sees it in their file, so a reported failure can
        // actually be found and fixed. A header row occupies line 1.
        $rowNumber = $options->hasHeader ? 1 : 0;

        /** @var array<int, string> $failures */
        $failures = [];

        foreach (array_chunk($rows, max(1, $importer->chunkSize())) as $chunk) {
            DB::transaction(function () use ($chunk, $importer, $rules, &$imported, &$failed, &$failures, &$rowNumber): void {
                foreach ($chunk as $row) {
                    $rowNumber++;
                    $data = $this->mapRow($row);

                    if ($rules !== []) {
                        $validator = Validator::make($data, $rules);

                        if ($validator->fails()) {
                            $failed++;
                            $this->recordFailure($failures, $rowNumber, (string) $validator->errors()->first());

                            continue;
                        }
                    }

                    try {
                        $importer->importRow($data);
                        $imported++;
                    } catch (Throwable $e) {
                        $failed++;
                        $this->recordFailure($failures, $rowNumber, $e->getMessage());
                    }
                }
            });
        }

        Storage::disk($disk)->delete($this->path);

        $this->notify($imported, $failed, $failures);
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
    protected function notify(int $imported, int $failed, array $failures = []): void
    {
        if ($this->recipientClass === null || $this->recipientId === null) {
            return;
        }

        $recipient = $this->recipientClass::find($this->recipientId);

        if ($recipient === null) {
            return;
        }

        $body = __('kinetix.import_complete_body', [
            'imported' => $imported,
            'failed'   => $failed,
        ]);

        if ($failures !== []) {
            $body .= "\n".implode("\n", $failures);

            if ($failed > count($failures)) {
                $body .= "\n".__('kinetix.import_failed_more', ['count' => $failed - count($failures)]);
            }
        }

        $notification = Notification::make()
            ->title((string) __('kinetix.import_complete'))
            ->body((string) $body)
            ->status($failed > 0 ? 'warning' : 'success');

        if (Notification::shouldBroadcast()) {
            $notification->broadcast($recipient);

            return;
        }

        $notification->sendToDatabase($recipient);
    }
}
