<?php

declare(strict_types=1);

namespace Happones\Kinetix\Activity;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The event spine: dispatched after every activity entry is recorded. Other
 * Kinetix modules (and host apps) can listen to react to domain changes — e.g.
 * the Webhooks module fans these out to subscribed customer endpoints.
 */
class ActivityLogged
{
    use Dispatchable;

    public function __construct(public Activity $activity) {}
}
