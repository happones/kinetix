<?php

declare(strict_types=1);

namespace Happones\Kinetix\NotificationRouting;

/**
 * Who a route points at. `Eligible` is every team member who passes the
 * type's `requires` ability, judged when the notification is sent.
 */
enum AudienceType: string
{
    case Member   = 'member';
    case Role     = 'role';
    case Group    = 'group';
    case Eligible = 'eligible';
}
