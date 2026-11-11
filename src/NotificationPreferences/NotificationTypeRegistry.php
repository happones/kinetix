<?php

declare(strict_types=1);

namespace Happones\Kinetix\NotificationPreferences;

use Happones\Kinetix\Support\ConfigCallback;

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
     * Whether a type can be routed, when declared in code. Without a
     * declaration only Kinetix's own types (`kinetix.*`) are not routable.
     *
     * @var array<string, bool>
     */
    protected array $routable = [];

    /**
     * Per type: whether routing is offered for the team (teamKey, type) => bool,
     * as the host gave it (resolved on every call, never held as an instance).
     *
     * @var array<string, mixed>
     */
    protected array $availability = [];

    /**
     * Per type: the text (or translation key) that says who gets the
     * notification when the team chose nobody.
     *
     * @var array<string, string>
     */
    protected array $fallbackDescriptions = [];

    /**
     * Per type: the people who get it when the team chose nobody,
     * (teamKey, notification) => iterable of users, as the host gave it.
     *
     * @var array<string, mixed>
     */
    protected array $fallbacks = [];

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

    public function makeRoutable(string $type, bool $routable): void
    {
        $this->routable[$type] = $routable;
    }

    /**
     * Whether a team can route the type at all: declared in code, or any type
     * but Kinetix's own, which go to the person who started the export or import.
     */
    public function isRoutable(string $type): bool
    {
        return $this->routable[$type] ?? ! str_starts_with($type, 'kinetix.');
    }

    /**
     * @param mixed $callback `fn (int|string|null $teamKey, string $type): bool`, kept as given
     */
    public function availableWhen(string $type, mixed $callback): void
    {
        $this->availability[$type] = $callback;
    }

    /**
     * Whether routing the type is offered to the team: routable, and the
     * host's availability callback (if any) agrees.
     */
    public function isAvailable(string $type, int|string|null $teamKey): bool
    {
        if (! $this->isRoutable($type)) {
            return false;
        }

        $callback = ConfigCallback::resolve($this->availability[$type] ?? null);

        return $callback === null || (bool) $callback($teamKey, $type);
    }

    public function describeFallback(string $type, string $description): void
    {
        $this->fallbackDescriptions[$type] = $description;
    }

    public function fallbackDescriptionOf(string $type): ?string
    {
        return $this->fallbackDescriptions[$type] ?? null;
    }

    /**
     * @param mixed $callback `fn (int|string|null $teamKey, ?object $notification): iterable<mixed>`, kept as given
     */
    public function fallbackUsing(string $type, mixed $callback): void
    {
        $this->fallbacks[$type] = $callback;
    }

    public function hasFallback(string $type): bool
    {
        return isset($this->fallbacks[$type]);
    }

    /**
     * The people the host's fallback names for the team, or an empty list.
     *
     * @return list<mixed>
     */
    public function fallbackFor(string $type, int|string|null $teamKey, ?object $notification): array
    {
        $callback = ConfigCallback::resolve($this->fallbacks[$type] ?? null);

        if ($callback === null) {
            return [];
        }

        $result = $callback($teamKey, $notification);

        if ($result === null) {
            return [];
        }

        return is_array($result) ? array_values($result) : iterator_to_array($result, false);
    }
}
