<?php

declare(strict_types=1);

namespace Happones\Kinetix\Locations;

use InvalidArgumentException;

/**
 * Resolves the location providers named in `kinetix.locations.providers`
 * (one cached instance per name and request).
 *
 * An entry's `driver` is `database` ({@see DatabaseLocationProvider}, or
 * {@see DatabasePostalCodeProvider} when the entry maps a `postal_codes`
 * table) or the class of a {@see LocationProvider}, built through the
 * container with the entry as `$config`. A provider class may also be named
 * directly. A provider that implements {@see PostalCodeProvider} comes back as
 * a {@see CachedPostalCodeProvider}.
 */
class KinetixLocations
{
    /**
     * @var array<string, CachedLocationProvider>
     */
    private array $resolved = [];

    /**
     * The provider AddressPicker::locations() uses when it names none.
     */
    public function defaultName(): string
    {
        return (string) config('kinetix.locations.default', 'world');
    }

    public function provider(?string $name = null): CachedLocationProvider
    {
        $name ??= $this->defaultName();

        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        $provider = $this->make($name);
        $ttl      = (int) config('kinetix.locations.cache_ttl', 86400);

        return $this->resolved[$name] = $provider instanceof PostalCodeProvider
            ? new CachedPostalCodeProvider($provider, $name, $ttl)
            : new CachedLocationProvider($provider, $name, $ttl);
    }

    /**
     * The provider, when it knows postal codes and neighborhoods.
     */
    public function postalCodes(?string $name = null): ?CachedPostalCodeProvider
    {
        $provider = $this->provider($name);

        return $provider instanceof CachedPostalCodeProvider ? $provider : null;
    }

    /**
     * Whether $name resolves to a provider (a configured entry or a class).
     */
    public function exists(string $name): bool
    {
        return is_array(config("kinetix.locations.providers.{$name}"))
            || (class_exists($name) && is_subclass_of($name, LocationProvider::class));
    }

    protected function make(string $name): LocationProvider
    {
        $config = config("kinetix.locations.providers.{$name}");

        if (! is_array($config)) {
            if (class_exists($name) && is_subclass_of($name, LocationProvider::class)) {
                return app()->make($name);
            }

            throw new InvalidArgumentException("Unknown location provider [{$name}]: add it to kinetix.locations.providers.");
        }

        $driver = $config['driver'] ?? 'database';

        if ($driver === 'database') {
            return is_array($config['postal_codes'] ?? null)
                ? new DatabasePostalCodeProvider($config)
                : new DatabaseLocationProvider($config);
        }

        if (is_string($driver) && class_exists($driver) && is_subclass_of($driver, LocationProvider::class)) {
            return app()->make($driver, ['config' => $config]);
        }

        throw new InvalidArgumentException("The location provider [{$name}] has an invalid driver: use 'database' or a LocationProvider class.");
    }
}
