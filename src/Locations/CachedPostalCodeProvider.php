<?php

declare(strict_types=1);

namespace Happones\Kinetix\Locations;

/**
 * {@see CachedLocationProvider} for a provider that knows postal codes: each
 * code's answer, each city's unfiltered neighborhoods and every neighborhood
 * lookup are cached the same way. Searches go to the source.
 */
class CachedPostalCodeProvider extends CachedLocationProvider implements PostalCodeProvider
{
    public function __construct(
        protected PostalCodeProvider $postalCodes,
        string $name,
        int $ttl,
    ) {
        parent::__construct($postalCodes, $name, $ttl);
    }

    public function postalCode(string $country, string $code): ?array
    {
        $code = trim($code);

        return $this->remember("postal.{$country}.{$code}", fn (): ?array => $this->postalCodes->postalCode($country, $code));
    }

    public function neighborhoods(string $country, ?string $state, string $city, string $search = '', int $limit = 200): array
    {
        if (trim($search) !== '') {
            return $this->postalCodes->neighborhoods($country, $state, $city, $search, $limit);
        }

        return $this->remember("neighborhoods.{$country}.{$state}.{$city}.{$limit}", fn (): array => $this->postalCodes->neighborhoods($country, $state, $city, '', $limit));
    }

    public function neighborhood(string $country, ?string $state, string $city, string $neighborhood): ?array
    {
        return $this->remember("neighborhood.{$country}.{$state}.{$city}.{$neighborhood}", fn (): ?array => $this->postalCodes->neighborhood($country, $state, $city, $neighborhood));
    }
}
