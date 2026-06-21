<?php

declare(strict_types=1);

namespace Happones\Kinetix\Exports\Jobs;

use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Exports\Exporter;
use Happones\Kinetix\Exports\FileWriter;
use Happones\Kinetix\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
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
     * @param class-string|null $recipientClass
     */
    public function __construct(
        protected string $exporterClass,
        protected ?string $recipientClass = null,
        protected int|string|null $recipientId = null,
    ) {}

    public function handle(): void
    {
        /** @var Exporter $exporter */
        $exporter = new $this->exporterClass();
        $format = $exporter->format();

        $storedName = Str::uuid()->toString().'.'.$format;
        $relativePath = $this->directory.'/'.$storedName;

        Storage::makeDirectory($this->directory);
        $absolutePath = Storage::path($relativePath);

        $writer = new FileWriter($absolutePath, $format);
        $writer->writeRow($exporter->headings());

        $exporter->query()->chunk($exporter->chunkSize(), function ($records) use ($writer, $exporter): void {
            foreach ($records as $record) {
                $writer->writeRow($exporter->mapRecord($record));
            }
        });

        $writer->close();

        $downloadName = $exporter->fileName().'.'.$format;
        $token = Crypt::encrypt(['path' => $relativePath, 'name' => $downloadName]);
        $url = route('kinetix.exports.download', ['token' => $token]);

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

        if (config('kinetix.broadcasting.echo')) {
            $notification->broadcast($recipient);

            return;
        }

        $notification->sendToDatabase($recipient);
    }
}
