<?php

declare(strict_types=1);

namespace Happones\Kinetix\Gdpr\Jobs;

use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Exports\DownloadToken;
use Happones\Kinetix\Gdpr\GdprManager;
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

/**
 * Builds the user's personal-data export (a JSON dump of every registered
 * section), stores it on the Kinetix disk, and notifies the user with a
 * download link bound to that user and expiring (reusing the exports download route).
 */
class GdprExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // Written under the exports directory so the download route can serve it.
    protected string $directory = 'kinetix-exports';

    public int $tries = 3;

    /**
     * @param class-string $userClass
     */
    public function __construct(
        protected string $userClass,
        protected int|string $userId,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /**
     * Runs once after $tries is exhausted. A GDPR export is a legal obligation
     * with a deadline, so a silent failure is the worst outcome — tell the user.
     */
    public function failed(Throwable $e): void
    {
        /** @var Model|null $user */
        $user = $this->userClass::find($this->userId);

        if ($user === null) {
            return;
        }

        $notification = Notification::make()
            ->title((string) __('kinetix.gdpr_export_failed'))
            ->body((string) __('kinetix.gdpr_export_failed_body'))
            ->danger();

        if (Notification::shouldBroadcast()) {
            $notification->broadcast($user);

            return;
        }

        $notification->sendToDatabase($user);
    }

    public function handle(): void
    {
        /** @var Model|null $user */
        $user = $this->userClass::find($this->userId);

        if ($user === null) {
            return;
        }

        $data = app(GdprManager::class)->collect($user);
        $json = (string) json_encode(
            $data,
            JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES,
        );

        $disk         = KinetixDisk::privateName();
        $storedName   = Str::uuid()->toString().'.json';
        $relativePath = $this->directory.'/'.$storedName;

        $tempPath = (string) tempnam(sys_get_temp_dir(), 'kinetix_gdpr_');
        file_put_contents($tempPath, $json);

        Storage::disk($disk)->putFileAs($this->directory, new File($tempPath), $storedName);
        @unlink($tempPath);

        $token = DownloadToken::mint($disk, $relativePath, 'my-data.json', $user->getKey());
        $url   = route('kinetix.exports.download', ['token' => $token]);

        $this->notify($user, $url);
    }

    protected function notify(Model $user, string $url): void
    {
        $notification = Notification::make()
            ->title((string) __('kinetix.gdpr_export_ready'))
            ->body((string) __('kinetix.gdpr_export_ready_body'))
            ->success()
            ->actions([
                Action::make('download')
                    ->label((string) __('kinetix.download_export'))
                    ->icon('download')
                    ->color('primary')
                    ->button()
                    ->url($url, true),
            ]);

        if (Notification::shouldBroadcast()) {
            $notification->broadcast($user);

            return;
        }

        $notification->sendToDatabase($user);
    }
}
