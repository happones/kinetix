import { usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type { KinetixSharedProps, KinetixSpotlightGroup } from '@/types';

/**
 * Queries the spotlight endpoint. Results are already authorization-filtered
 * server-side, so the palette just renders what it gets back.
 */
export function useKinetixSpotlight() {
    const page = usePage<KinetixSharedProps>();
    const base = (): string => `/${kinetixRoutePrefix(page)}/spotlight`;

    const loading = ref(false);

    async function search(query: string): Promise<KinetixSpotlightGroup[]> {
        loading.value = true;

        try {
            const result = await kinetixFetch<{
                groups: KinetixSpotlightGroup[];
            }>(`${base()}?q=${encodeURIComponent(query)}`);

            return result?.groups ?? [];
        } finally {
            loading.value = false;
        }
    }

    return { loading, search };
}
