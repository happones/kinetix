<?php

declare(strict_types=1);

namespace Happones\Kinetix\ConnectedAccounts;

/**
 * The catalog of OAuth providers offered for linking / social login. Seeded
 * from `config('kinetix.connected_accounts.providers')` and/or
 * `KinetixConnectedAccounts::providers([...])`.
 *
 * Each provider normalizes to `{label, icon, color}`. A string value is treated
 * as the label (icon defaults to the provider key).
 *
 * @phpstan-type ProviderConfig array{label: string, icon: string, color: string|null}
 */
class ConnectedAccountProviderRegistry
{
    /**
     * @var array<string, ProviderConfig>
     */
    protected array $providers = [];

    /**
     * @param array<string, string|array{label?: string, icon?: string, color?: string|null}> $providers
     */
    public function register(array $providers): void
    {
        foreach ($providers as $key => $config) {
            if (is_string($config)) {
                $config = ['label' => $config];
            }

            $this->providers[$key] = [
                'label' => $config['label'] ?? ucfirst($key),
                'icon'  => $config['icon']  ?? $key,
                'color' => $config['color'] ?? null,
            ];
        }
    }

    /**
     * @return array<string, ProviderConfig>
     */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->providers);
    }

    public function has(string $provider): bool
    {
        return array_key_exists($provider, $this->providers);
    }
}
