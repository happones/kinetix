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
        KinetixNotificationPreferences::RECORDS => ['database', 'broadcast'],
    ];

    /**
     * The channels a type is delivered on whatever the user chose, declared
     * in code only.
     *
     * @var array<string, list<string>>
     */
    protected array $mandatory = [];

    /**
     * The ability a recipient must hold for a type to reach them.
     *
     * @var array<string, string>
     */
    protected array $requires = [];

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

    /**
     * Declare the channels a type is delivered on even when the user turned
     * them off (a security alert, a billing notice). Code only: nothing in
     * the UI or the API can set it.
     *
     * @param list<string> $channels
     */
    public function mandate(string $type, array $channels): void
    {
        $this->mandatory[$type] = array_values(array_unique($channels));
    }

    /**
     * The channels a user cannot opt out of for a type.
     *
     * @return list<string>
     */
    public function mandatoryOf(string $type): array
    {
        return $this->mandatory[$type] ?? [];
    }

    /**
     * Declare the ability a recipient must hold, judged when the notification
     * is sent, for a type to reach them.
     */
    public function require(string $type, string $ability): void
    {
        $this->requires[$type] = $ability;
    }

    public function requirementOf(string $type): ?string
    {
        return $this->requires[$type] ?? null;
    }
}
