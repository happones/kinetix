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

    /**
     * @param class-string<Importer>  $importerClass
     * @param array<string, mixed>    $options
     * @param array<string, int|null> $mapping        column name => source header index
     * @param class-string|null       $recipientClass
     */
    public function __construct(
        protected string $importerClass,
        protected string $path,
        protected array $options,
        protected array $mapping,
        protected ?string $recipientClass = null,
        protected int|string|null $recipientId = null,
    ) {}

    public function handle(): void
    {
        /** @var Importer $importer */
        $importer = new $this->importerClass;
        $options  = ImportOptionsData::from($this->options);

        $disk                 = KinetixDisk::name();
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

        foreach (array_chunk($rows, max(1, $importer->chunkSize())) as $chunk) {
            DB::transaction(function () use ($chunk, $importer, $rules, &$imported, &$failed): void {
                foreach ($chunk as $row) {
                    $data = $this->mapRow($row);

                    if ($rules !== [] && Validator::make($data, $rules)->fails()) {
                        $failed++;

                        continue;
                    }

                    try {
                        $importer->importRow($data);
                        $imported++;
                    } catch (Throwable $e) {
                        $failed++;
                    }
                }
            });
        }

        Storage::disk($disk)->delete($this->path);

        $this->notify($imported, $failed);
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
     * Notify the user that the import finished.
     */
    protected function notify(int $imported, int $failed): void
    {
        if ($this->recipientClass === null || $this->recipientId === null) {
            return;
        }

        $recipient = $this->recipientClass::find($this->recipientId);

        if ($recipient === null) {
            return;
        }

        $body = trans('kinetix.import_complete_body', [
            'imported' => $imported,
            'failed'   => $failed,
        ]);

        $notification = Notification::make()
            ->title((string) trans('kinetix.import_complete'))
            ->body((string) $body)
            ->status($failed > 0 ? 'warning' : 'success');

        if (Notification::shouldBroadcast()) {
            $notification->broadcast($recipient);

            return;
        }

        $notification->sendToDatabase($recipient);
    }
}
