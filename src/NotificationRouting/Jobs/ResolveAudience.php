<?php

declare(strict_types=1);

namespace Happones\Kinetix\NotificationRouting\Jobs;

use Happones\Kinetix\NotificationRouting\AudienceResolver;
use Happones\Kinetix\NotificationRouting\Events\AudienceResolved;
use Happones\Kinetix\NotificationRouting\RecipientPlan;
use Happones\Kinetix\NotificationRouting\UserRefs;
use Happones\Kinetix\Support\Memo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Works out who a routed notification reaches, then hands them to
 * {@see DeliverRouted} in chunks, one group of people per channel set. The
 * team was captured when the notification was routed and is never inferred
 * here.
 */
class ResolveAudience implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Never retried: a retry after some chunks were queued would notify
     * their people twice.
     */
    public int $tries = 1;

    /**
     * @param list<array{0: string, 1: int|string}> $fallback
     */
    public function __construct(
        public readonly string $type,
        public readonly int|string|null $teamKey,
        public readonly object $notification,
        public readonly array $fallback = [],
        public readonly bool $sync = false,
    ) {
        $queue = config('kinetix.notification_routing.queue');

        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function handle(AudienceResolver $resolver): void
    {
        // A worker outlives the rules an admin edits between jobs.
        Memo::flush(AudienceResolver::MEMO);

        $plan = $resolver->resolve($this->type, $this->teamKey, $this->notification, UserRefs::load($this->fallback));

        event(new AudienceResolved(
            $this->type,
            $this->teamKey,
            $plan->countsPerChannel(),
            count($plan->recipients),
            $plan->skippedCount,
            $plan->hasRules,
            $plan->fellBack,
            $plan->truncated,
        ));

        $this->deliver($plan);
    }

    public function failed(Throwable $e): void
    {
        Log::error('Notification routing: the audience could not be resolved; nobody was notified.', ['type' => $this->type, 'team' => $this->teamKey, 'error' => $e->getMessage()]);
    }

    protected function deliver(RecipientPlan $plan): void
    {
        $groups = [];

        foreach ($plan->recipients as $recipient) {
            $channels = $recipient->channels;
            sort($channels);

            $groups[implode(',', $channels)]['channels'] = $channels;
            $groups[implode(',', $channels)]['users'][]  = $recipient->user;
        }

        $size = max(1, (int) config('kinetix.notification_routing.chunk', 100));

        foreach ($groups as $group) {
            foreach (array_chunk(UserRefs::ofMany($group['users']), $size) as $chunk) {
                $job = new DeliverRouted($this->type, $this->teamKey, $this->notification, $chunk, $group['channels']);

                $this->sync ? dispatch_sync($job) : dispatch($job);
            }
        }
    }
}
