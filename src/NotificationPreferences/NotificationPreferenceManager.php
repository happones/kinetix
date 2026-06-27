<?php

declare(strict_types=1);

namespace Happones\Kinetix\NotificationPreferences;

use Illuminate\Database\Eloquent\Model;

/**
 * Resolves and persists per-user notification preferences (a type × channel
 * matrix). Defaults to enabled — only opt-outs are stored — so newly added
 * types/channels are on until the user turns them off.
 */
class NotificationPreferenceManager
{
    public function __construct(protected NotificationTypeRegistry $registry) {}

    /**
     * The available channels (key => label).
     *
     * @return array<string, string>
     */
    public function channels(): array
    {
        /** @var array<string, string> $channels */
        $channels = (array) config('kinetix.notification_preferences.channels', []);

        return $channels;
    }

    /**
     * The full matrix for a user: channels + each type with its per-channel state.
     *
     * @return array{channels: array<int, array{key: string, label: string}>, types: array<int, array{key: string, label: string, channels: array<string, bool>}>}
     */
    public function for(Model $user): array
    {
        $stored   = $this->stored($user);
        $channels = $this->channels();

        $types = [];
        foreach ($this->registry->all() as $type => $label) {
            $state = [];
            foreach ($channels as $channel => $channelLabel) {
                $state[$channel] = $stored[$type][$channel] ?? true;
            }
            $types[] = ['key' => $type, 'label' => $label, 'channels' => $state];
        }

        return [
            'channels' => array_map(
                static fn (string $key, string $label): array => ['key' => $key, 'label' => $label],
                array_keys($channels),
                array_values($channels),
            ),
            'types' => $types,
        ];
    }

    /**
     * Set one type/channel preference for a user.
     */
    public function update(Model $user, string $type, string $channel, bool $enabled): void
    {
        $record = NotificationPreference::query()->firstOrNew(['user_id' => $user->getKey()]);

        $preferences = $record->preferences ?? [];
        $preferences[$type] ??= [];
        $preferences[$type][$channel] = $enabled;

        $record->preferences = $preferences;
        $record->save();
    }

    /**
     * Whether the user accepts a given type on a given channel (default: yes).
     */
    public function allows(Model $user, string $type, string $channel): bool
    {
        return $this->stored($user)[$type][$channel] ?? true;
    }

    /**
     * Filter candidate channels by the user's preferences for a type.
     *
     * @param  array<int, string> $channels
     * @return array<int, string>
     */
    public function channelsFor(Model $user, string $type, array $channels): array
    {
        $stored = $this->stored($user);

        return array_values(array_filter(
            $channels,
            static fn (string $channel): bool => $stored[$type][$channel] ?? true,
        ));
    }

    /**
     * @return array<string, array<string, bool>>
     */
    protected function stored(Model $user): array
    {
        $record = NotificationPreference::query()->where('user_id', $user->getKey())->first();

        if ($record === null) {
            return [];
        }

        return $record->preferences ?? [];
    }
}
