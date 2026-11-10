<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Fakes;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A host notification: mail and database, nothing else. Counts how often
 * Laravel asked it for its own channels.
 */
class RoutedAlert extends Notification
{
    public static int $viaCalls = 0;

    public function __construct(public string $title = 'Low stock') {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        self::$viaCalls++;

        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->title);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title];
    }
}
