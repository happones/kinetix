<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tokens;

/**
 * Static entry point for developer tokens. Declare the grantable scopes in a
 * provider:
 *
 *     KinetixTokens::scopes(['posts.read' => 'Read posts', 'posts.write' => 'Write posts']);
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
}
