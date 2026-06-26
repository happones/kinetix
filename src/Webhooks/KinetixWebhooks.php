<?php

declare(strict_types=1);

namespace Happones\Kinetix\Webhooks;

/**
 * Static entry point for webhooks. Declare the subscribable events and fire them:
 *
 *     KinetixWebhooks::events(['order.created' => 'Order created']); // in a provider
 *     KinetixWebhooks::fire('order.created', ['id' => $order->id]);  // in your domain code
 *
 * Firing queues a signed delivery to every active, subscribed endpoint.
 */
class KinetixWebhooks
{
    public static function registry(): WebhookEventRegistry
    {
        return app(WebhookEventRegistry::class);
    }

    public static function dispatcher(): WebhookDispatcher
    {
        return app(WebhookDispatcher::class);
    }

    /**
     * @param array<int|string, string> $events
     */
    public static function events(array $events): void
    {
        static::registry()->register($events);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fire(string $event, array $payload = []): void
    {
        static::dispatcher()->fire($event, $payload);
    }
}
