<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

/**
 * The same requests in an app without teams: the team key is null.
 */
class NotificationRoutingRequestsNoTeamsTest extends NotificationRoutingRequestsTest
{
    protected function teamsOn(): bool
    {
        return false;
    }
}
