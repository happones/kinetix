<?php

declare(strict_types=1);

namespace Happones\Kinetix\Settings;

/**
 * Static entry point for the Settings module:
 *
 *     KinetixSettings::get('general.site_name', 'Acme');
 *     KinetixSettings::set('general.maintenance_mode', true);
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
