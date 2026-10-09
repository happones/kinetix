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
     * The channels a type is delivered on, when not every channel: the
     * matrix offers only those, since switching another one does nothing.
     * Kinetix's own notifications go to the database and broadcast.
     *
     * @var array<string, list<string>>
     */
    protected array $channels = [
        KinetixNotificationPreferences::EXPORTS => ['database', 'broadcast'],
        KinetixNotificationPreferences::IMPORTS => ['database', 'broadcast'],
        KinetixNotificationPreferences::REPORTS => ['database', 'broadcast'],
    ];

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

    /**
     * Declare the channels a type is delivered on.
     *
     * @param list<string> $channels
     */
    public function deliverOn(string $type, array $channels): void
    {
        $this->channels[$type] = $channels;
    }

    /**
     * The channels a type is delivered on, or null for every channel.
     *
     * @return list<string>|null
     */
    public function channelsOf(string $type): ?array
    {
        return $this->channels[$type] ?? null;
    }
}
