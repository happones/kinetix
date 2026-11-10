<?php

declare(strict_types=1);

namespace Happones\Kinetix\NotificationRouting;

use Happones\Kinetix\NotificationPreferences\NotificationTypeRegistry;

/**
 * The routing metadata of one notification type: who must hold which ability
 * to receive it, and which channels the user cannot opt out of. Both live in
 * the notification type registry and are set in code only.
 *
 *     KinetixNotificationRouting::type('inventory.low-stock')
 *         ->requires('products.viewAny')
 *         ->mandatory(['mail']);
 */
class RoutableType
{
    public function __construct(
        protected NotificationTypeRegistry $registry,
        protected string $type,
    ) {}

    /**
     * The ability a recipient must hold, judged when the notification is
     * sent: whoever a route reaches but lacks it is left out.
     */
    public function requires(string $ability): static
    {
        $this->registry->require($this->type, $ability);

        return $this;
    }

    /**
     * Channels delivered whatever the user's preferences say. For things the
     * person must receive (a security alert, a billing notice), never for
     * marketing. The UI and the API cannot set this.
     *
     * @param list<string> $channels
     */
    public function mandatory(array $channels): static
    {
        $this->registry->mandate($this->type, $channels);

        return $this;
    }
}
