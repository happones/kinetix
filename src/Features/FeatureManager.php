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

        if ($this->usesPennant()) {
            $resolved = $scope !== null ? Feature::for($scope)->all() : Feature::all();

            return array_map(static fn ($value): bool => (bool) $value, $resolved);
        }

        $result = [];

        foreach (array_keys($this->definitions) as $name) {
            $result[$name] = $this->resolveNative($name, $scope);
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
