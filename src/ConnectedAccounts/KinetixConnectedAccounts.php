<?php

declare(strict_types=1);

namespace Happones\Kinetix\ConnectedAccounts;

use Closure;

/**
 * Static entry point for the Connected Accounts / social-auth feature. Declare
 * providers and (optionally) customize how the guest login flow resolves or
 * creates users:
 *
 *     KinetixConnectedAccounts::providers([
 *         'github' => ['label' => 'GitHub', 'icon' => 'github', 'color' => '#181717'],
 *         'google' => ['label' => 'Google', 'icon' => 'google', 'color' => '#4285F4'],
 *     ]);
 *
 *     // Optional: take full control of login user resolution / creation.
 *     KinetixConnectedAccounts::createUserUsing(function (object $socialUser, string $provider) {
 *         // e.g. create the user AND their personal team
 *     });
 */
class KinetixConnectedAccounts
{
    /**
     * Resolve an existing user for the social identity, or null. Signature:
     * `fn (object $socialUser, string $provider): ?Authenticatable`.
     */
    protected static ?Closure $userResolver = null;

    /**
     * Create a new user for the social identity. Signature:
     * `fn (object $socialUser, string $provider): Authenticatable`.
     */
    protected static ?Closure $userCreator = null;

    public static function registry(): ConnectedAccountProviderRegistry
    {
        return app(ConnectedAccountProviderRegistry::class);
    }

    /**
     * @param array<string, string|array{label?: string, icon?: string, color?: string|null}> $providers
     */
    public static function providers(array $providers): void
    {
        static::registry()->register($providers);
    }

    public static function resolveUserUsing(Closure $callback): void
    {
        static::$userResolver = $callback;
    }

    public static function createUserUsing(Closure $callback): void
    {
        static::$userCreator = $callback;
    }

    public static function userResolver(): ?Closure
    {
        return static::$userResolver;
    }

    public static function userCreator(): ?Closure
    {
        return static::$userCreator;
    }

    /**
     * Reset the registered callbacks (intended for tests).
     */
    public static function flush(): void
    {
        static::$userResolver = null;
        static::$userCreator  = null;
    }
}
