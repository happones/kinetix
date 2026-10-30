import { kinetixFetch } from '@/composables/useKinetixHttp';
import type {
    KinetixLocationList,
    KinetixPostalCodeMatch,
} from '@/types/kinetix';

/**
 * States, cities, neighborhoods and postal codes for an AddressPicker in
 * locations mode, from the `forms/locations` endpoint.
 *
 * Answers are kept for the page's lifetime in one cache shared by every
 * picker — a repeater of twenty addresses asks for Mexico's states once — and
 * a request already in flight is reused, not repeated. Searches go through the
 * same cache, which is bounded so a long session of typing can't grow it
 * without limit.
 */

export interface KinetixLocationQuery {
    token: string;
    level: 'states' | 'cities' | 'neighborhoods';
    country: string;
    state?: string | null;
    /** The city whose neighborhoods to list. */
    city?: string | null;
    q?: string;
}

const MAX_ENTRIES = 300;

const cache = new Map<string, Promise<unknown>>();

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

    if (query.city) {
        params.set('city', query.city);
    }

    if (query.q) {
        params.set('q', query.q);
    }

    return cached(
        `/${prefix}/forms/locations?${params.toString()}`,
        (result: KinetixLocationList | null) => result ?? EMPTY,
    );
}

/**
 * What a postal code tells (state, city, neighborhoods), or null when no
 * place has it.
 */
export function fetchKinetixPostalCode(
    prefix: string,
    query: { token: string; country: string; code: string },
): Promise<KinetixPostalCodeMatch | null> {
    const params = new URLSearchParams({
        token: query.token,
        level: 'postal-code',
        country: query.country,
        code: query.code,
    });

    return cached(
        `/${prefix}/forms/locations?${params.toString()}`,
        (result: { match: KinetixPostalCodeMatch | null } | null) =>
            result?.match ?? null,
    );
}

function cached<R, T>(url: string, read: (result: R | null) => T): Promise<T> {
    const hit = cache.get(url);

    if (hit) {
        return hit as Promise<T>;
    }

    const request = kinetixFetch<R>(url)
        .then(read)
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
