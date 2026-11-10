<?php

declare(strict_types=1);

namespace Happones\Kinetix\NotificationRouting\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An admin created, changed or removed a rule or a group. For an activity
 * log: `$subject` says which, `$before` / `$after` hold the changed values
 * (null on the side that does not exist).
 */
class NotificationRouteChanged
{
    use Dispatchable;

    /**
     * @param 'created'|'updated'|'deleted' $action
     * @param 'route'|'group'               $subject
     * @param array<string, mixed>|null     $before
     * @param array<string, mixed>|null     $after
     */
    public function __construct(
        public readonly string $action,
        public readonly string $subject,
        public readonly int|string|null $team,
        public readonly int|string|null $actor,
        public readonly ?array $before,
        public readonly ?array $after,
    ) {}
}
