import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import {
    isKinetixAbort,
    kinetixFetch,
    kinetixRoutePrefix,
} from '@/composables/useKinetixHttp';
import type {
    KinetixSharedProps,
    KinetixSpotlightGroup,
} from '@/types/kinetix';

/**
 * Queries the spotlight endpoint. Results are already authorization-filtered
 * server-side, so the palette just renders what it gets back.
 *
 * Searches supersede each other: typing outruns the round-trip, and without
 * cancellation the response for `emp` can land after the one for `empleado`
 * and win — showing hits for a query the reader already replaced, and doing it
 * more often exactly as load rises. Each call aborts the one before it and
 * resolves to **`null`** when it was superseded, so a caller can tell "no
 * results" (`[]`) from "don't paint this" (`null`).
 */
export function useKinetixSpotlight() {
    const page = usePage<KinetixSharedProps>();
    const base = (): string => `/${kinetixRoutePrefix(page)}/spotlight`;

    const loading = ref(false);

    /** The endpoint ignores anything shorter; the palette shouldn't ask. */
    const minChars = computed(
        () => page.props.kinetix_config?.spotlight?.min_chars ?? 2,
    );

    let controller: AbortController | undefined;
    let latest = 0;

    async function search(
        query: string,
    ): Promise<KinetixSpotlightGroup[] | null> {
        controller?.abort();
        controller = new AbortController();

        const request = ++latest;

        loading.value = true;

        try {
            const result = await kinetixFetch<{
                groups: KinetixSpotlightGroup[];
            }>(`${base()}?q=${encodeURIComponent(query)}`, {
                signal: controller.signal,
            });

            // Belt and braces: `abort()` covers the fetch, the sequence number
            // covers anything that resolved between the abort and this line.
            return request === latest ? (result?.groups ?? []) : null;
        } catch (error) {
            if (isKinetixAbort(error)) {
                return null;
            }

            throw error;
        } finally {
            // Only the newest request owns the spinner — a superseded one
            // switching it off would hide an in-flight search.
            if (request === latest) {
                loading.value = false;
            }
        }
    }

    return { loading, minChars, search };
}
