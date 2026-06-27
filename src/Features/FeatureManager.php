<?php

declare(strict_types=1);

namespace Happones\Kinetix\Features;

use Closure;
use Laravel\Pennant\Feature;

/**
 * Resolves feature flags through the configured driver: `laravel/pennant` when
 * installed (the standard, with persistence/lottery/rich scopes), otherwise a
 * native closure evaluator. Flags are defined with closures that receive the
 * scope (the user, or the team when `features.teams` is on), so they can defer
 * to anything — including Billing's `$user->canUseFeature(...)` for plan-gating.
 */
class FeatureManager
{
    /**
     * Native fallback definitions.
     *
     * @var array<string, Closure|bool>
     */
    protected array $definitions = [];

    public function define(string $name, Closure|bool $resolver): void
    {
        $this->definitions[$name] = $resolver;

        if ($this->usesPennant()) {
            Feature::define($name, $resolver instanceof Closure ? $resolver : fn (): bool => $resolver);
        }
    }

    public function active(string $name, mixed $scope = null): bool
    {
        $scope ??= $this->defaultScope();

        // Guests resolve to a null scope. A user/team-scoped resolver (e.g.
        // `fn ($user) => $user->isBetaTester()`) can't run for a guest, so treat
        // it as inactive instead of letting an NPE 500 the page (this resolves on
        // every Inertia response). Authenticated scopes still surface real errors.
        if ($scope === null) {
            try {
                return $this->resolve($name, null);
            } catch (\Throwable) {
                return false;
            }
        }

        return $this->resolve($name, $scope);
    }

    protected function resolve(string $name, mixed $scope): bool
    {
        if ($this->usesPennant()) {
            return $scope !== null
                ? (bool) Feature::for($scope)->active($name)
                : (bool) Feature::active($name);
        }

        return $this->resolveNative($name, $scope);
    }

    public function inactive(string $name, mixed $scope = null): bool
    {
        return ! $this->active($name, $scope);
    }

    /**
     * All defined flags resolved to booleans for the scope (for the frontend).
     *
     * @return array<string, bool>
     */
    public function all(mixed $scope = null): array
    {
        $scope ??= $this->defaultScope();

        // Authenticated Pennant: resolve in bulk (efficient, unchanged).
        if ($this->usesPennant() && $scope !== null) {
            return array_map(static fn ($value): bool => (bool) $value, Feature::for($scope)->all());
        }

        // Native, or any guest (null scope): resolve per-flag through active(),
        // so one throwing resolver can't break the whole set and guests get
        // `false` rather than a 500. Names come from define() (always recorded).
        $result = [];

        foreach (array_keys($this->definitions) as $name) {
            $result[$name] = $this->active($name, $scope);
        }

        return $result;
    }

    public function usesPennant(): bool
    {
        $driver = (string) config('kinetix.features.driver', 'auto');

        return match ($driver) {
            'native'  => false,
            'pennant' => true,
            default   => class_exists(Feature::class),
        };
    }

    protected function resolveNative(string $name, mixed $scope): bool
    {
        $definition = $this->definitions[$name] ?? false;

        if (is_bool($definition)) {
            return $definition;
        }

        return (bool) $definition($scope);
    }

    protected function defaultScope(): mixed
    {
        $user = auth()->user();

        if (config('kinetix.features.teams', false) && $user?->currentTeam !== null) {
            return $user->currentTeam;
        }

        return $user;
    }
}
