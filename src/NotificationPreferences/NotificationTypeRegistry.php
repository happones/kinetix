<?php

declare(strict_types=1);

namespace Happones\Kinetix\NotificationPreferences;

/**
 * The catalog of notification types users can opt in/out of (key => human
 * label). Seeded from `config('kinetix.notification_preferences.types')` and/or
 * `KinetixNotificationPreferences::types([...])`. Bound as a singleton.
 */
class NotificationTypeRegistry
{
    /**
     * @var array<string, string>
     */
    protected array $types = [];

    /**
     * @param array<int|string, string> $types key=>label, or a plain list of keys
     */
    public function register(array $types): void
    {
        foreach ($types as $key => $label) {
            if (is_int($key)) {
                $this->types[$label] = $label;

                continue;
            }

            $this->types[$key] = $label;
        }
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->types;
    }

    public function has(string $type): bool
    {
        return array_key_exists($type, $this->types);
    }
}
