<?php

declare(strict_types=1);

namespace Happones\Kinetix\NotificationRouting;

/**
 * Why a team member a route reached does not get the notification.
 */
enum SkipReason: string
{
    /** They fail the type's `requires` ability. */
    case Ineligible = 'ineligible';

    /** They turned off every channel the route sends on. */
    case OptedOut = 'opted_out';

    /** None of the route's channels is one the type is delivered on, or the notification can render. */
    case NoChannels = 'no_channels';
}
