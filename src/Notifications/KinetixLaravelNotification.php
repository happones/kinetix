<?php

declare(strict_types=1);

namespace Happones\Kinetix\Notifications;

use Happones\Kinetix\NotificationPreferences\NotificationPreferenceManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification as BaseLaravelNotification;

class KinetixLaravelNotification extends BaseLaravelNotification implements ShouldQueue
{
    use Queueable;

    /**
     * The preference type the channels are checked against. A plain property
     * with a default (not promoted), so a notification queued before it
     * existed still unserializes with a value.
     */
    public ?string $type = null;

    /**
     * Whether the database row is stored already read: history that must not
     * raise the badge or toast again. A plain property for the same reason as
     * `$type`.
     */
    public bool $markAsRead = false;

    /**
     * Create a new notification instance.
     *
     * @param array<string, mixed> $data
     * @param array<int, string>   $channels
     */
    public function __construct(
        public array $data,
        public array $channels = ['database'],
        ?string $type = null,
        bool $markAsRead = false,
    ) {
        $this->type       = $type;
        $this->markAsRead = $markAsRead;
    }

    /**
     * Get the notification's delivery channels: the ones the notifiable
     * hasn't turned off for this notification's type.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = $this->channels;

        if ($this->type !== null && $notifiable instanceof Model) {
            $channels = app(NotificationPreferenceManager::class)->deliverable($notifiable, $this->type, $channels);
        }

        if (! $this->markAsRead) {
            return $channels;
        }

        return array_map(
            static fn (string $channel): string => $channel === 'database' ? ReadDatabaseChannel::class : $channel,
            $channels,
        );
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->data;
    }

    /**
     * Get the broadcast representation of the notification.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->data);
    }
}
