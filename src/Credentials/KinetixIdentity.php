<?php

declare(strict_types=1);

namespace Happones\Kinetix\Credentials;

use Illuminate\Database\Eloquent\Model;

/**
 * Static entry point for login identity — which fields identify a user, and
 * who a given login string refers to.
 *
 * The whole of what Fortify needs is one line:
 *
 *     Fortify::authenticateUsing(fn (Request $request) => KinetixIdentity::attempt(
 *         $request->input(Fortify::username()),
 *         $request->input('password'),
 *     ));
 *
 * Kinetix does **not** own your login: `attempt()` resolves and verifies, and
 * hands the user back for Fortify to log in. Everything about sessions, throttling
 * and two-factor stays exactly where it was.
 */
class KinetixIdentity
{
    public static function resolver(): IdentityResolver
    {
        return app(IdentityResolver::class);
    }

    /**
     * The columns a login may be matched against (default `['email']`).
     *
     * @return array<int, string>
     */
    public static function fields(): array
    {
        return static::resolver()->fields();
    }

    public static function accepts(string $field): bool
    {
        return static::resolver()->accepts($field);
    }

    /**
     * Whether anything beyond plain email login is configured.
     */
    public static function enabled(): bool
    {
        return static::resolver()->enabled();
    }

    /**
     * The canonical stored form of a value — apply it on the way IN too, or the
     * unique index cannot see the duplicates it is meant to stop.
     */
    public static function normalize(string $field, ?string $value): string
    {
        return static::resolver()->normalize($field, $value);
    }

    /**
     * Which accepted fields a login string could be. Usually exactly one.
     *
     * @return array<int, string>
     */
    public static function classify(?string $login): array
    {
        return static::resolver()->classify($login);
    }

    public static function resolve(?string $login): ?Model
    {
        return static::resolver()->resolve($login);
    }

    /**
     * Resolve a login and verify the password. Null for every failure — one
     * outcome, so the form is not a directory anyone can enumerate.
     */
    public static function attempt(?string $login, ?string $password): ?Model
    {
        return static::resolver()->attempt($login, $password);
    }

    /**
     * Validation rules for the accepted identifiers.
     *
     * @param  Model|int|string|null            $ignore the record being updated
     * @return array<string, array<int, mixed>>
     */
    public static function rules(mixed $ignore = null): array
    {
        return static::resolver()->rules($ignore);
    }
}
