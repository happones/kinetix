<?php

declare(strict_types=1);

namespace Happones\Kinetix\Exports\Jobs;

use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Exports\Exporter;
use Happones\Kinetix\Exports\FileWriter;
use Happones\Kinetix\Notifications\Notification;
use Happones\Kinetix\Support\KinetixDisk;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\File;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExportProcessor implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    protected string $directory = 'kinetix-exports';

    /**
     * @param class-string<Exporter> $exporterClass
     * @param class-string|null      $recipientClass
     * @param array<string, mixed>   $parameters
     */
    public function __construct(
        protected string $exporterClass,
        protected ?string $recipientClass = null,
        protected int|string|null $recipientId = null,
        protected array $parameters = [],
    ) {}

    public function handle(): void
    {
        /** @var Exporter $exporter */
        $exporter = (new $this->exporterClass)->withParameters($this->parameters);
        $format   = $exporter->format();

        $storedName   = Str::uuid()->toString().'.'.$format;
        $relativePath = $this->directory.'/'.$storedName;
        $disk         = KinetixDisk::name();

        // Write to a local temp file (the writer needs a real path), then put it
        // on the configured disk so exports work on any driver (local, s3, …).
        $tempPath = (string) tempnam(sys_get_temp_dir(), 'kinetix_export_');

        $writer = new FileWriter($tempPath, $format);
        $writer->writeRow($exporter->headings());

        $exporter->resolveExportQuery()->chunk($exporter->chunkSize(), function ($records) use ($writer, $exporter): void {
            foreach ($records as $record) {
                $writer->writeRow($exporter->mapRecord($record));
            }
        });

        $writer->close();

        Storage::disk($disk)->putFileAs($this->directory, new File($tempPath), $storedName);
        @unlink($tempPath);

        $downloadName = $exporter->fileName().'.'.$format;
        $token        = Crypt::encrypt(['disk' => $disk, 'path' => $relativePath, 'name' => $downloadName]);
        $url          = route('kinetix.exports.download', ['token' => $token]);

        $this->notify($url);
    }

    protected function notify(string $url): void
    {
        if ($this->recipientClass === null || $this->recipientId === null) {
            return;
        }

        /** @var Model|null $recipient */
        $recipient = $this->recipientClass::find($this->recipientId);

        if ($recipient === null) {
            return;
        }

        $notification = Notification::make()
            ->title((string) trans('kinetix.export_ready'))
            ->body((string) trans('kinetix.export_ready_body'))
            ->success()
            ->actions([
                Action::make('download')
                    ->label((string) trans('kinetix.download_export'))
                    ->icon('download')
                    ->color('primary')
                    ->button()
                    ->url($url, true),
            ]);

        if (Notification::shouldBroadcast()) {
            $notification->broadcast($recipient);

            return;
        }

        $notification->sendToDatabase($recipient);
    }
}
