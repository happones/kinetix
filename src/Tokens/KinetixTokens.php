<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tokens;

use Illuminate\Database\Eloquent\Model;

/**
 * Static entry point for developer tokens. Declare the grantable scopes in a
 * provider:
 *
 *     KinetixTokens::scopes(['posts.read' => 'Read posts', 'posts.write' => 'Write posts']);
 *     KinetixTokens::defaultScopes(['posts.read']);
 */
class KinetixTokens
{
    public static function registry(): TokenScopeRegistry
    {
        return app(TokenScopeRegistry::class);
    }

    /**
     * @param array<int|string, string> $scopes
     */
    public static function scopes(array $scopes): void
    {
        static::registry()->register($scopes);
    }

    /**
     * The scopes the create form starts with ticked.
     *
     * @param array<int, string> $keys
     */
    public static function defaultScopes(array $keys): void
    {
        static::registry()->defaults($keys);
    }

    /**
     * The team a token acts on: the `tokens.team_column` value when the token
     * has one, else the `tokens.team_ability` seal (`team:7` -> 7). Null for a
     * token minted before tokens carried a team.
     *
     * The seal is read from the stored ability LIST, never through
     * `tokenCan()`: a `*` token "can" every ability, including another team's.
     */
    public static function teamOf(Model $token): int|string|null
    {
        $column = config('kinetix.tokens.team_column');

        if (is_string($column) && $column !== '') {
            $value = $token->getAttribute($column);

            if (is_int($value) || (is_string($value) && $value !== '')) {
                return $value;
            }
        }

        $prefix = static::sealPrefix();

        if ($prefix === null) {
            return null;
        }

        $abilities = $token->getAttribute('abilities');

        // A catalog scope that happens to start with the prefix is a scope.
        $scopes = static::registry()->keys();

        foreach (is_array($abilities) ? $abilities : [] as $ability) {
            if (! is_string($ability) || ! str_starts_with($ability, $prefix) || strlen($ability) === strlen($prefix) || in_array($ability, $scopes, true)) {
                continue;
            }

            $key = substr($ability, strlen($prefix));

            return ctype_digit($key) && strlen($key) < 19 ? (int) $key : $key;
        }

        return null;
    }

    /**
     * The catalog scopes that collide with plumbing: they start with the seal
     * prefix or equal a stamp ability, so they can neither be granted nor told
     * apart from a seal.
     *
     * @return array<int, string>
     */
    public static function collidingScopes(): array
    {
        $prefix = static::sealPrefix();
        $stamps = static::stampAbilities();

        return array_values(array_filter(
            static::registry()->keys(),
            static fn (string $scope): bool => ($prefix !== null && str_starts_with($scope, $prefix)) || in_array($scope, $stamps, true),
        ));
    }

    /**
     * The `tokens.team_ability` prefix, null when ability sealing is off.
     */
    public static function sealPrefix(): ?string
    {
        $prefix = config('kinetix.tokens.team_ability');

        return is_string($prefix) && $prefix !== '' ? $prefix : null;
    }

    /**
     * Whether an ability is plumbing (a team seal or a stamped ability) that
     * the token list keeps out of the UI and the create form may not set.
     *
     * @internal
     */
    public static function isInternalAbility(string $ability): bool
    {
        $prefix = static::sealPrefix();

        if ($prefix !== null && str_starts_with($ability, $prefix)) {
            return true;
        }

        return in_array($ability, static::stampAbilities(), true);
    }

    /**
     * The abilities `tokens.stamp_abilities` adds to every new token.
     *
     * @return array<int, string>
     */
    public static function stampAbilities(): array
    {
        return array_values(array_filter(
            (array) config('kinetix.tokens.stamp_abilities', []),
            static fn (mixed $ability): bool => is_string($ability) && $ability !== '',
        ));
    }
}
