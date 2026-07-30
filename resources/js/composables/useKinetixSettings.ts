import { usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type {
    KinetixSettingsPageData,
    KinetixSharedProps,
} from '@/types/kinetix';

/**
 * Loads and persists a Kinetix settings page. Talks to the `settings` endpoint;
 * the route prefix (incl. any team segment) comes from the shared
 * `kinetix_config`. `load()` lets a component self-fetch its page DTO so it can
 * be dropped into the host's own settings layout without a host controller.
 */
export function useKinetixSettings() {
    const page = usePage<KinetixSharedProps>();
    const base = (): string => `/${kinetixRoutePrefix(page)}/settings`;

    const loading = ref(false);
    const saving = ref(false);

    async function load(
        pageKey: string,
    ): Promise<KinetixSettingsPageData | null> {
        loading.value = true;

        try {
            return await kinetixFetch<KinetixSettingsPageData>(
                `${base()}/${pageKey}`,
            );
        } finally {
            loading.value = false;
        }
    }

    async function save(
        pageKey: string,
        values: Record<string, unknown>,
    ): Promise<unknown> {
        saving.value = true;

        try {
            return await kinetixFetch(`${base()}/${pageKey}`, {
                method: 'PUT',
                body: values,
            });
        } finally {
            saving.value = false;
        }
    }

    return { loading, saving, load, save };
}
