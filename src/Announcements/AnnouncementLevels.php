<?php

declare(strict_types=1);

namespace Happones\Kinetix\Announcements;

/**
 * The levels an announcement can carry, each with the status color and icon
 * it shows with. Configured in `kinetix.announcements.levels`; a level that
 * isn't configured (published from code with any slug) reads as neutral.
 *
 *     'levels' => [
 *         'maintenance' => ['color' => 'warning', 'icon' => 'wrench'],
 *     ],
 *
 * Label a level in your lang file as `kinetix.announcements_level_{slug}`.
 */
class AnnouncementLevels
{
    public const COLORS = ['success', 'danger', 'warning', 'info', 'primary', 'gray'];

    /** @var array<string, array{color: string, icon: string|null}> */
    public const DEFAULTS = [
        'info'    => ['color' => 'gray', 'icon' => 'info'],
        'feature' => ['color' => 'success', 'icon' => 'sparkles'],
        'fix'     => ['color' => 'info', 'icon' => 'wrench'],
    ];

    /**
     * @return array<string, array{color: string, icon: string|null}>
     */
    public static function all(): array
    {
        $configured = config('kinetix.announcements.levels');

        if (! is_array($configured) || $configured === []) {
            return self::DEFAULTS;
        }

        $levels = [];

        foreach ($configured as $slug => $level) {
            if (! is_string($slug) || ! is_array($level)) {
                continue;
            }

            $levels[$slug] = static::normalize($level);
        }

        return $levels === [] ? self::DEFAULTS : $levels;
    }

    /**
     * @return array{color: string, icon: string|null}
     */
    public static function for(string $level): array
    {
        return static::all()[$level] ?? ['color' => 'gray', 'icon' => null];
    }

    /**
     * What the authoring form offers.
     *
     * @return list<array{value: string, color: string, icon: string|null}>
     */
    public static function options(): array
    {
        $options = [];

        foreach (static::all() as $slug => $level) {
            $options[] = ['value' => $slug] + $level;
        }

        return $options;
    }

    /**
     * @param  array<mixed>                            $level
     * @return array{color: string, icon: string|null}
     */
    protected static function normalize(array $level): array
    {
        $color = $level['color'] ?? 'gray';
        $icon  = $level['icon']  ?? null;

        return [
            'color' => is_string($color) && in_array($color, self::COLORS, true) ? $color : 'gray',
            'icon'  => is_string($icon)  && $icon !== '' ? $icon : null,
        ];
    }
}
