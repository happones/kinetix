<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tokens;

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
}
