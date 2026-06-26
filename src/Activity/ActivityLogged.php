<?php

declare(strict_types=1);

namespace Happones\Kinetix\Activity;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The event spine: dispatched after every activity entry is recorded. Other
 * Kinetix modules (and host apps) can listen to react to domain changes — e.g.
 * the Webhooks module fans these out to subscribed customer endpoints.
 *
 * `$activity` is the stored record — the native {@see Activity} model, or
 * spatie's Activity model when that driver is active. Normalize it with
 * `ActivityData::fromModel($event->activity)` for a driver-agnostic payload.
 */
class ActivityLogged
{
    use Dispatchable;

    public function __construct(public Model $activity) {}
}
