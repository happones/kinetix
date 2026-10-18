<?php

declare(strict_types=1);

namespace Happones\Kinetix\Inbox;

use Happones\Kinetix\Channels\ChannelManager;
use Happones\Kinetix\Channels\DownloadsMedia;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Fetch the file of a message a contact sent from its provider and keep it on
 * the inbox disk, so it shows in the thread (and outlives the provider's
 * short-lived links).
 */
class DownloadConversationMedia implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public int|string $messageId) {}

    public function handle(ChannelManager $channels, InboxManager $inbox): void
    {
        $message = ConversationMessage::query()->find($this->messageId);

        if ($message === null || $message->media_path !== null || $message->media_ref === null) {
            return;
        }

        $conversation = $message->conversation;
        $driver       = $channels->for($conversation->channel);

        if (! $driver instanceof DownloadsMedia) {
            return;
        }

        $media = $driver->downloadMedia($conversation->channel, $message->media_ref);

        if ($media === null) {
            return;
        }

        $mime      = $media->mime ?? $message->media_mime ?? 'application/octet-stream';
        $extension = $this->extension($mime, $media->name ?? $message->media_name);
        $path      = trim((string) config('kinetix.inbox.directory', 'kinetix/inbox'), '/')."/{$conversation->getKey()}/".Str::ulid().$extension;

        Storage::disk($inbox->disk())->put($path, $media->contents);

        $message->update([
            'media_path' => $path,
            'media_mime' => $mime,
            'media_name' => $message->media_name ?? $media->name,
            'media_size' => strlen($media->contents),
        ]);

        $inbox->broadcast($conversation, $message);
    }

    protected function extension(string $mime, ?string $name): string
    {
        $fromName = $name !== null ? pathinfo($name, PATHINFO_EXTENSION) : '';

        if ($fromName !== '') {
            return '.'.strtolower($fromName);
        }

        $subtype = Str::of($mime)->after('/')->before(';')->replace(['x-', 'jpeg', 'quicktime'], ['', 'jpg', 'mov'])->toString();

        return preg_match('/^[a-z0-9]{2,5}$/', $subtype) === 1 ? ".{$subtype}" : '';
    }
}
