<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Fakes;

use Illuminate\Notifications\Notification;

/**
 * A host notification that can only render for the database.
 */
class RoutedDatabaseOnlyAlert extends Notification
{
    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return ['title' => 'Only in app'];
    }
}
