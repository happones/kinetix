<?php

declare(strict_types=1);

namespace Happones\Kinetix\Locale;

use Illuminate\Database\Eloquent\Model;

/**
 * Static entry point for the locale / language switcher:
 *
 *     KinetixLocale::set('es');               // persist + apply for the current user
 *     KinetixLocale::current();               // 'es'
 *     KinetixLocale::options();               // [['code' => 'en', 'label' => 'English'], ...]
 */
class KinetixLocale
{
    public static function manager(): LocaleManager
    {
        return app(LocaleManager::class);
    }

    /**
     * @return array<int, array{code: string, label: string}>
     */
    public static function options(): array
    {
        return static::manager()->options();
    }

    public static function current(): string
    {
        return static::manager()->current();
    }

    public static function set(string $code, ?Model $user = null): bool
    {
        return static::manager()->set($code, $user ?? (auth()->user() instanceof Model ? auth()->user() : null));
    }
}
