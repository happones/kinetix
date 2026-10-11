<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

/**
 * The label a column, filter, field or entry gets when the app gives it none,
 * derived from its name and passed through the translator: `user_agent` reads
 * "User Agent" in English and whatever the app's JSON catalog says elsewhere.
 * Without the translation step every derived label stayed in English in every
 * locale, even when the catalog had the key.
 */
class AutoLabel
{
    /**
     * `created_at` / `user.name` → the translated "Created At" / "User Name".
     * A catalog keyed in sentence case ("Created at") is found too, since that
     * is how most apps write their keys.
     */
    public static function from(string $name): string
    {
        return static::translate((string) str(str_replace('.', ' ', $name))->headline());
    }

    /**
     * The translation of a derived label, trying its headline form first and
     * its sentence-case form second; the headline form when neither exists.
     */
    public static function translate(string $headline): string
    {
        if (trans()->has($headline)) {
            return (string) __($headline);
        }

        $sentence = ucfirst(mb_strtolower($headline));

        if ($sentence !== $headline && trans()->has($sentence)) {
            return (string) __($sentence);
        }

        return $headline;
    }
}
