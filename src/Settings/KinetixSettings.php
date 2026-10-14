<?php

declare(strict_types=1);

namespace Happones\Kinetix\Settings;

use Illuminate\Database\Eloquent\Model;

/**
 * Static entry point for the Settings module:
 *
 *     KinetixSettings::get('general.site_name', 'Acme');      // the team's, else the global value
 *     KinetixSettings::set('general.maintenance_mode', true);
 *     KinetixSettings::global()->set('general.site_name', 'Acme'); // what every team inherits
 *     KinetixSettings::pages([GeneralSettingsPage::class]); // typically in a provider
 */
class KinetixSettings
{
    public static function manager(): SettingsManager
    {
        return app(SettingsManager::class);
    }

    public static function registry(): SettingsRegistry
    {
        return app(SettingsRegistry::class);
    }

    /**
     * The settings of one team (`null`: the global ones), whatever team the
     * request serves.
     */
    public static function forTeam(Model|int|string|null $team): SettingsManager
    {
        return static::manager()->forTeam($team);
    }

    /**
     * The global settings every team inherits.
     */
    public static function global(): SettingsManager
    {
        return static::manager()->global();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::manager()->get($key, $default);
    }

    public static function set(string $key, mixed $value, bool $encrypted = false): void
    {
        static::manager()->set($key, $value, $encrypted);
    }

    public static function forget(string $key): void
    {
        static::manager()->forget($key);
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return static::manager()->all();
    }

    /**
     * Register one or more settings pages.
     *
     * @param array<int, class-string<SettingsPage>> $pages
     */
    public static function pages(array $pages): void
    {
        static::registry()->register($pages);
    }
}
