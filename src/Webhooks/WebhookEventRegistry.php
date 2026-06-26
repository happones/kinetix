<?php

declare(strict_types=1);

namespace Happones\Kinetix\Webhooks;

/**
 * The catalog of subscribable platform events (name => human label). Only
 * registered events can be subscribed to or fired. Bound as a singleton;
 * populated via `KinetixWebhooks::events([...])`.
 */
class WebhookEventRegistry
{
    /**
     * @var array<string, string>
     */
    protected array $events = [];

    /**
     * @param array<int|string, string> $events name=>label, or a plain list of names
     */
    public function register(array $events): void
    {
        foreach ($events as $name => $label) {
            if (is_int($name)) {
                $this->events[$label] = $label;

                continue;
            }

            $this->events[$name] = $label;
        }
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->events;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->events);
    }
}
