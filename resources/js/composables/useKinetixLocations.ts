import { kinetixFetch } from '@/composables/useKinetixHttp';
import type { KinetixLocationList } from '@/types/kinetix';

/**
 * States and cities for an AddressPicker in locations mode, from the
 * `forms/locations` endpoint.
 *
 * Answers are kept for the page's lifetime in one cache shared by every
 * picker — a repeater of twenty addresses asks for Mexico's states once — and
 * a request already in flight is reused, not repeated. Searches go through the
 * same cache, which is bounded so a long session of typing can't grow it
 * without limit.
 */

export interface KinetixLocationQuery {
    token: string;
    level: 'states' | 'cities';
    country: string;
    state?: string | null;
    q?: string;
}

const MAX_ENTRIES = 300;

const cache = new Map<string, Promise<KinetixLocationList>>();

const EMPTY: KinetixLocationList = { options: [], complete: true };

export function fetchKinetixLocations(
    prefix: string,
    query: KinetixLocationQuery,
): Promise<KinetixLocationList> {
    const params = new URLSearchParams({
        token: query.token,
        level: query.level,
        country: query.country,
    });

    if (query.state) {
        params.set('state', query.state);
    }

    if (query.q) {
        params.set('q', query.q);
    }

    const url = `/${prefix}/forms/locations?${params.toString()}`;
    const cached = cache.get(url);

    if (cached) {
        return cached;
    }

    const request = kinetixFetch<KinetixLocationList>(url)
        .then((result) => result ?? EMPTY)
        .catch((error: unknown) => {
            // A failed answer isn't kept: the next open tries again.
            cache.delete(url);

            throw error;
        });

    if (cache.size >= MAX_ENTRIES) {
        cache.delete(cache.keys().next().value as string);
    }

    cache.set(url, request);

    return request;
}

/** For tests: forget every cached answer. */
export function clearKinetixLocationsCache(): void {
    cache.clear();
}
