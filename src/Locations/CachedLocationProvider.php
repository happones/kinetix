<?php

declare(strict_types=1);

namespace Happones\Kinetix\Locations;

use Illuminate\Support\Facades\Cache;

/**
 * Caches a provider's static answers: the country list, each country's states,
 * each state's first page of cities and every label lookup — in memory for the
 * request and in the cache store for `kinetix.locations.cache_ttl` seconds.
 * Searches (a typed term) go to the source: they don't repeat enough to be
 * worth a cache entry each.
 */
class CachedLocationProvider implements LocationProvider
{
    /**
     * @var array<string, mixed>
     */
    private array $memo = [];

    public function __construct(
        protected LocationProvider $provider,
        protected string $name,
        protected int $ttl,
    ) {}

    public function inner(): LocationProvider
    {
        return $this->provider;
    }

    public function countries(): array
    {
        return $this->remember('countries', fn (): array => $this->provider->countries());
    }

    public function states(string $country, string $search = '', int $limit = 1000): array
    {
        if (trim($search) !== '') {
            return $this->provider->states($country, $search, $limit);
        }

        return $this->remember("states.{$country}.{$limit}", fn (): array => $this->provider->states($country, '', $limit));
    }

    public function cities(string $country, ?string $state, string $search = '', int $limit = 50): array
    {
        if (trim($search) !== '') {
            return $this->provider->cities($country, $state, $search, $limit);
        }

        return $this->remember("cities.{$country}.{$state}.{$limit}", fn (): array => $this->provider->cities($country, $state, '', $limit));
    }

    public function stateLabel(string $country, string $state): ?string
    {
        return $this->remember("state.{$country}.{$state}", fn (): ?string => $this->provider->stateLabel($country, $state));
    }

    public function cityLabel(string $country, ?string $state, string $city): ?string
    {
        return $this->remember("city.{$country}.{$state}.{$city}", fn (): ?string => $this->provider->cityLabel($country, $state, $city));
    }

    /**
     * @template T
     *
     * @param  callable(): T $resolve
     * @return T
     */
    protected function remember(string $key, callable $resolve): mixed
    {
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        if ($this->ttl <= 0) {
            return $this->memo[$key] = $resolve();
        }

        // Wrapped so a null label (not found) is cached too, not re-queried.
        $entry = Cache::remember("kinetix.locations.{$this->name}.".md5($key), $this->ttl, fn (): array => ['value' => $resolve()]);

        return $this->memo[$key] = $entry['value'];
    }
}
