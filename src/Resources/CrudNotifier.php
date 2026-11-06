<?php

declare(strict_types=1);

namespace Happones\Kinetix\Resources;

use Happones\Kinetix\Flash\KinetixFlash;
use Happones\Kinetix\NotificationPreferences\KinetixNotificationPreferences;
use Happones\Kinetix\Notifications\KinetixLaravelNotification;
use Happones\Kinetix\Notifications\Notification;
use Happones\Kinetix\Support\KinetixTeams;

/**
 * Delivers a resource's CRUD message. Reached through
 * `Resource::sendCrudNotification()` and `RelationManager::sendCrudNotification()`.
 *
 * @internal
 */
final class CrudNotifier
{
    public static function defaultNotification(CrudEvent $event): Notification
    {
        return Notification::make()->success()->title((string) __($event->messageKey()));
    }

    /**
     * Stamp the type and team (a null type or team is stamped; only a value
     * you set is kept), then toast and/or persist. An empty title sends
     * nothing. Returns what was sent, or null. A failure while persisting is
     * reported and swallowed: the write has already committed.
     */
    public static function deliver(Notification $notification, CrudNotificationDelivery $delivery): ?Notification
    {
        if ($notification->getTitle() === '') {
            return null;
        }

        if ($notification->getType() === null) {
            $notification->type(KinetixNotificationPreferences::RECORDS);
        }

        if ($notification->getTeam() === null) {
            $notification->team(KinetixTeams::keyFor('notifications'));
        }

        if ($delivery->toasts()) {
            $status = $notification->getStatus();

            KinetixFlash::toast(
                $notification->getTitle(),
                $status === 'danger' ? 'error' : $status,
                $notification->getBody(),
            );
        }

        if ($delivery->persists()) {
            // The write has committed: a failing store or queue is reported,
            // never turned into an error the user would retry into a duplicate.
            rescue(static fn () => self::persist($notification, $delivery), report: true);
        }

        return $notification;
    }

    private static function persist(Notification $notification, CrudNotificationDelivery $delivery): void
    {
        $recipient = $notification->getRecipient() ?? auth()->user();

        if ($recipient === null || ! method_exists($recipient, 'notify')) {
            return;
        }

        if ($delivery === CrudNotificationDelivery::ToastAndDatabase) {
            $recipient->notify(new KinetixLaravelNotification(
                $notification->toArray(),
                ['database'],
                $notification->getType(),
                markAsRead: true,
            ));

            return;
        }

        if (Notification::shouldBroadcast()) {
            $notification->broadcast($recipient);

            return;
        }

        $notification->sendToDatabase($recipient);
    }
}
