<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows\Nodes;

use Happones\Kinetix\Flows\FlowStep;
use Happones\Kinetix\Flows\NodeOutcome;
use Happones\Kinetix\Support\WeeklySchedule;

/** Branches on business hours: `schedule` (a weekly schedule) in `timezone`, to `open` or `closed`. */
class HoursNode extends BaseNode
{
    public function outputs(array $data): array
    {
        return ['open', 'closed'];
    }

    public function execute(FlowStep $step): NodeOutcome
    {
        $timezone = (string) ($step->data['timezone'] ?? '') ?: (string) config('app.timezone', 'UTC');
        $schedule = WeeklySchedule::fromArray($step->data['schedule'] ?? WeeklySchedule::businessWeek()->toArray());

        return NodeOutcome::next($schedule->isOpenAt($step->now()->copy()->setTimezone($timezone)) ? 'open' : 'closed');
    }
}
