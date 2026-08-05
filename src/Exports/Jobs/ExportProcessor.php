<?php

declare(strict_types=1);

namespace Happones\Kinetix\Exports\Jobs;

use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Exports\DownloadToken;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ExportProcessor implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    protected string $directory = 'kinetix-exports';

    public int $tries = 3;

    /**
     * @param class-string<Exporter> $exporterClass
     * @param class-string|null      $recipientClass
     * @param array<string, mixed>   $parameters
     * @param int|string|null        $teamKey        captured at dispatch (the worker has no
     *                                               request); stamps the notification when
     *                                               notifications are team-scoped
     */
    public function __construct(
        protected string $exporterClass,
        protected ?string $recipientClass = null,
        protected int|string|null $recipientId = null,
        protected array $parameters = [],
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
     * Runs once after $tries is exhausted. The user was promised an export and is
     * waiting for a notification, so tell them it failed instead of leaving the
     * job to die silently in `failed_jobs`.
     */
    public function failed(Throwable $e): void
    {
        $recipient = $this->resolveRecipient();

        if ($recipient === null) {
            return;
        }

        /** @var Exporter $exporter */
        $exporter = new $this->exporterClass;

        $notification = Notification::make()
            ->title($exporter->getFailedNotificationTitle())
            ->body($exporter->getFailedNotificationBody())
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
        /** @var Exporter $exporter */
        $exporter = (new $this->exporterClass)->withParameters($this->parameters);
        $format   = $exporter->format();

        $storedName   = Str::uuid()->toString().'.'.$format;
        $relativePath = $this->directory.'/'.$storedName;
        $disk         = KinetixDisk::privateName();

        // Write to a local temp file (the writer needs a real path), then put it
        // on the configured disk so exports work on any driver (local, s3, …).
        $tempPath = (string) tempnam(sys_get_temp_dir(), 'kinetix_export_');

        $writer = new FileWriter($tempPath, $format);
        $writer->writeRow($exporter->headings());

        $exported = 0;
        $failed   = 0;

        // A record whose mapping throws is skipped — one bad row must not cost
        // the user the other N thousand. The completion notification reports
        // how many were skipped.
        $exporter->resolveExportQuery()->chunk($exporter->chunkSize(), function ($records) use ($writer, $exporter, &$exported, &$failed): void {
            foreach ($records as $record) {
                try {
                    $writer->writeRow($exporter->mapRecord($record));
                    $exported++;
                } catch (Throwable $e) {
                    $failed++;
                }
            }
        });

        // Append the totals/summary row when any column declares summarizers.
        $summaryRow = $exporter->summaryRow($exporter->resolveExportQuery());
        if ($summaryRow !== null) {
            $writer->writeRow($summaryRow);
        }

        $writer->close();

        Storage::disk($disk)->putFileAs($this->directory, new File($tempPath), $storedName);
        @unlink($tempPath);

        $downloadName = $exporter->fileName().'.'.$format;
        $token        = DownloadToken::mint($disk, $relativePath, $downloadName, $this->recipientId);
        $url          = route('kinetix.exports.download', ['token' => $token]);

        $this->notify($exporter, $url, $exported, $failed);
    }

    /**
     * The user to notify, or null when the export wasn't dispatched for one.
     */
    protected function resolveRecipient(): ?Model
    {
        if ($this->recipientClass === null || $this->recipientId === null) {
            return null;
        }

        /** @var Model|null $recipient */
        $recipient = $this->recipientClass::find($this->recipientId);

        return $recipient;
    }

    protected function notify(Exporter $exporter, string $url, int $exported, int $failed): void
    {
        $recipient = $this->resolveRecipient();

        if ($recipient === null) {
            return;
        }

        $notification = Notification::make()
            ->title($exporter->getCompletedNotificationTitle($exported, $failed))
            ->body($exporter->getCompletedNotificationBody($exported, $failed))
            ->team($this->teamKey)
            ->status($failed > 0 ? 'warning' : 'success')
            ->actions([
                Action::make('download')
                    ->label((string) __('kinetix.download_export'))
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
