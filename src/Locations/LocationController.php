<?php

declare(strict_types=1);

namespace Happones\Kinetix\Locations;

use Happones\Kinetix\Support\DescriptorRejection;
use Happones\Kinetix\Support\SignedDescriptor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The states of a country and the cities of a state, for an AddressPicker
 * in locations mode. The field's signed token names the provider, so only
 * a provider a form declared can be queried.
 *
 * `complete` says whether the list is the whole set (fewer than the limit):
 * the picker then filters it locally instead of searching on every keystroke.
 * Answers are cacheable by the browser — the data barely ever changes.
 */
class LocationController
{
    public function __invoke(Request $request, KinetixLocations $locations): JsonResponse
    {
        $descriptor = SignedDescriptor::open((string) $request->query('token'));
        $name       = is_array($descriptor) ? ($descriptor['locations'] ?? null) : null;

        if (! is_string($name) || ! $locations->exists($name)) {
            return response()->json(['message' => __('kinetix.search_invalid_token')], 400);
        }

        $rejection = SignedDescriptor::rejection($descriptor, $request);

        if ($rejection !== null) {
            return response()->json([
                'message' => $rejection === DescriptorRejection::Expired
                    ? __('kinetix.search_expired')
                    : __('kinetix.search_forbidden'),
            ], 403);
        }

        $level   = (string) $request->query('level');
        $country = strtoupper(substr((string) $request->query('country'), 0, 3));
        $state   = $request->filled('state') ? substr((string) $request->query('state'), 0, 64) : null;
        $search  = mb_substr(trim((string) $request->query('q', '')), 0, 100);

        if (! in_array($level, ['states', 'cities'], true) || $country === '') {
            return response()->json(['message' => __('kinetix.locations_invalid_request')], 422);
        }

        $provider = $locations->provider($name);
        $limit    = $level === 'states'
            ? max(1, (int) config('kinetix.locations.states_limit', 1000))
            : max(1, (int) config('kinetix.locations.cities_limit', 50));

        $options = $level === 'states'
            ? $provider->states($country, $search, $limit)
            : $provider->cities($country, $state, $search, $limit);

        return response()
            ->json([
                'options'  => $options,
                'complete' => $search === '' && count($options) < $limit,
            ])
            ->header('Cache-Control', 'private, max-age='.max(0, (int) config('kinetix.locations.browser_cache', 3600)));
    }
}
