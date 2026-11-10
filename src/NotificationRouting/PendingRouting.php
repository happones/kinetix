<?php

declare(strict_types=1);

namespace Happones\Kinetix\NotificationRouting;

use Happones\Kinetix\NotificationRouting\Jobs\ResolveAudience;
use Happones\Kinetix\Notifications\KinetixLaravelNotification;
use Happones\Kinetix\Notifications\Notification;
use Happones\Kinetix\Support\KinetixTeams;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * One notification of a type about to go to a team's routes. Build it with
 * `KinetixNotificationRouting::for($type, $teamKey)`.
 *
 * The team is captured here, at dispatch: the queued worker never infers it.
 * A notification is delivered to a person once, however many rules reach them,
 * on the union of those rules' channels, and never on a channel the person
 * turned off, unless the type makes it mandatory.
 */
class PendingRouting
{
    /** @var Collection<int, Model> */
    protected Collection $fallback;

    public function __construct(
        protected string $type,
        protected int|string|null $teamKey,
    ) {
        $this->fallback = new Collection;
    }

    /**
     * Who gets the notification when the team has no rule for the type (the
     * team owners, say). Their channels are the type's own; eligibility and
     * preferences still apply.
     *
     * @param iterable<mixed> $users
     */
    public function fallback(iterable $users): static
    {
        $this->fallback = collect($users)->filter(static fn (mixed $user): bool => $user instanceof Model)->values();

        return $this;
    }

    /**
     * Queue the delivery: the audience is resolved and fanned out on the
     * `notification_routing.queue` queue.
     */
    public function notify(object $notification): void
    {
        $this->dispatch($notification, sync: false);
    }

    /**
     * Deliver within this process.
     */
    public function notifyNow(object $notification): void
    {
        $this->dispatch($notification, sync: true);
    }

    /**
     * Queue a Kinetix in-app notification (database and broadcast).
     */
    public function send(Notification $notification): void
    {
        $this->dispatch($this->wrap($notification), sync: false);
    }

    public function sendNow(Notification $notification): void
    {
        $this->dispatch($this->wrap($notification), sync: true);
    }

    /**
     * A dry run: who would be notified, on which channels, and who is left
     * out and why. Sends nothing. Without a notification, every channel the
     * type is delivered on counts as renderable.
     */
    public function recipients(?object $notification = null): RecipientPlan
    {
        return app(AudienceResolver::class)->resolve($this->type, $this->teamKey, $notification, $this->fallback);
    }

    protected function dispatch(object $notification, bool $sync): void
    {
        $job = new ResolveAudience($this->type, $this->teamKey, $notification, UserRefs::ofMany($this->fallback), $sync);

        $sync ? dispatch_sync($job) : dispatch($job);
    }

    /**
     * The builder as the notification a worker sends, stamped with the type
     * and, with team-scoped notifications, the team.
     */
    protected function wrap(Notification $notification): KinetixLaravelNotification
    {
        $data = $notification->toArray();

        if ($this->teamKey !== null && KinetixTeams::enabledFor('notifications')) {
            $data['team'] ??= $this->teamKey;
        }

        return new KinetixLaravelNotification($data, ['database', 'broadcast'], $this->type);
    }
}
