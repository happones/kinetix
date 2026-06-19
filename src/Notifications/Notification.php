<?php

declare(strict_types=1);

namespace Happones\Kinetix\Notifications;

use Happones\Kinetix\Actions\Action;
use Illuminate\Notifications\Messages\BroadcastMessage;

class Notification
{
    protected string $id;

    protected string $title = '';

    protected ?string $body = null;

    protected string $status = 'info'; // info, success, warning, danger

    protected ?int $duration = 6000; // default duration 6 seconds (6000ms)

    protected ?string $icon = null;

    protected ?string $iconColor = null;

    protected array $actions = [];

    protected mixed $recipient = null;

    public function __construct()
    {
        $this->id = uniqid('kinetix_', true);
    }

    /**
     * Create a new notification instance.
     */
    public static function make(): static
    {
        return new static;
    }

    /**
     * Set the recipient of the notification.
     */
    public function to(mixed $recipient): static
    {
        $this->recipient = $recipient;

        return $this;
    }

    /**
     * Set the title of the notification.
     */
    public function title(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    /**
     * Set the body text of the notification.
     */
    public function body(?string $body): static
    {
        $this->body = $body;

        return $this;
    }

    /**
     * Alias for setting the body text of the notification.
     */
    public function description(?string $description): static
    {
        return $this->body($description);
    }

    /**
     * Set the status level of the notification.
     */
    public function status(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    /**
     * Set the duration in milliseconds.
     */
    public function duration(?int $duration): static
    {
        $this->duration = $duration;

        return $this;
    }

    /**
     * Set the duration in seconds.
     */
    public function seconds(int $seconds): static
    {
        return $this->duration($seconds * 1000);
    }

    /**
     * Make the notification persistent (never auto-closes).
     */
    public function persistent(): static
    {
        return $this->duration(null);
    }

    /**
     * Set an icon class or name for the notification.
     */
    public function icon(?string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    /**
     * Set the color of the notification icon.
     */
    public function iconColor(?string $iconColor): static
    {
        $this->iconColor = $iconColor;

        return $this;
    }

    /**
     * Add action buttons or links to the notification.
     *
     * @param array<int, Action> $actions
     */
    public function actions(array $actions): static
    {
        $this->actions = $actions;

        return $this;
    }

    /**
     * Set status to success.
     */
    public function success(): static
    {
        return $this->status('success');
    }

    /**
     * Set status to warning.
     */
    public function warning(): static
    {
        return $this->status('warning');
    }

    /**
     * Set status to danger.
     */
    public function danger(): static
    {
        return $this->status('danger');
    }

    /**
     * Set status to info.
     */
    public function info(): static
    {
        return $this->status('info');
    }

    /**
     * Get the unique ID of the notification.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Convert notification to array format.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $actionsArray = array_map(fn ($action) => $action->toArray(), $this->actions);

        return [
            'id'          => $this->id,
            'title'       => $this->title,
            'description' => $this->body,
            'status'      => $this->status,
            'duration'    => $this->duration,
            'icon'        => $this->icon,
            'iconColor'   => $this->iconColor,
            'actions'     => $actionsArray,
            'created_at'  => now()->toIso8601String(),
        ];
    }

    /**
     * Dispatch flash notification via session.
     */
    public function send(): static
    {
        $isDatabase = (bool) config('kinetix.notifications.database', false);

        if ($isDatabase) {
            $recipient = $this->recipient ?? auth()->user();

            if ($recipient !== null) {
                return $this->sendToDatabase($recipient);
            }
        }

        $notifications   = session()->get('kinetix_notifications', []);
        $notifications[] = $this->toArray();
        session()->flash('kinetix_notifications', $notifications);

        return $this;
    }

    /**
     * Dispatch database notification using Laravel's database channel.
     */
    public function sendToDatabase(mixed $recipient = null, bool $isEventDispatched = false): static
    {
        $recipient ??= $this->recipient ?? auth()->user();

        if ($recipient !== null && method_exists($recipient, 'notify')) {
            $channels = $isEventDispatched ? ['database', 'broadcast'] : ['database'];
            $recipient->notify(new KinetixLaravelNotification($this->toArray(), $channels));
        }

        return $this;
    }

    /**
     * Dispatch broadcast notification to real-time WebSockets.
     */
    public function broadcast(mixed $recipient = null): static
    {
        $recipient ??= $this->recipient ?? auth()->user();

        if ($recipient !== null && method_exists($recipient, 'notify')) {
            $recipient->notify(new KinetixLaravelNotification($this->toArray(), ['database', 'broadcast']));
        }

        return $this;
    }

    /**
     * Get database representation (for notify method).
     *
     * @return array<string, mixed>
     */
    public function toDatabase(): array
    {
        return $this->toArray();
    }

    /**
     * Get broadcast representation (for notify method).
     */
    public function toBroadcast(): KinetixLaravelNotification
    {
        return new KinetixLaravelNotification($this->toArray(), ['database', 'broadcast']);
    }

    /**
     * Get database message alias.
     *
     * @return array<string, mixed>
     */
    public function getDatabaseMessage(): array
    {
        return $this->toArray();
    }

    /**
     * Get broadcast message alias.
     */
    public function getBroadcastMessage(): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray());
    }
}
