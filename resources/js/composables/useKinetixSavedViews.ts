import { usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type { KinetixSavedView, KinetixSharedProps } from '@/types';

/**
 * Self-service saved table views for a given view key: load, create, update,
 * delete and set-default. Every mutation returns the refreshed list.
 */
export function useKinetixSavedViews(viewKey: string) {
    const page = usePage<KinetixSharedProps>();
    const base = (): string => `/${kinetixRoutePrefix(page)}/saved-views`;

    const views = ref<KinetixSavedView[]>([]);
    const loading = ref(false);

    async function load(): Promise<void> {
        loading.value = true;

        try {
            const q = new URLSearchParams({ key: viewKey });
            const data = await kinetixFetch<{ views: KinetixSavedView[] }>(
                `${base()}?${q.toString()}`,
            );
            views.value = data?.views ?? [];
        } finally {
            loading.value = false;
        }
    }

    async function create(
        name: string,
        state: Record<string, unknown>,
        isDefault = false,
    ): Promise<void> {
        const data = await kinetixFetch<{ views: KinetixSavedView[] }>(base(), {
            method: 'POST',
            body: { key: viewKey, name, state, is_default: isDefault },
        });
        views.value = data?.views ?? views.value;
    }

    async function update(
        view: KinetixSavedView,
        name: string,
        state: Record<string, unknown>,
    ): Promise<void> {
        const data = await kinetixFetch<{ views: KinetixSavedView[] }>(
            `${base()}/${view.id}`,
            { method: 'PUT', body: { name, state } },
        );
        views.value = data?.views ?? views.value;
    }

    async function remove(view: KinetixSavedView): Promise<void> {
        const data = await kinetixFetch<{ views: KinetixSavedView[] }>(
            `${base()}/${view.id}`,
            { method: 'DELETE' },
        );
        views.value = data?.views ?? views.value;
    }

    async function setDefault(view: KinetixSavedView): Promise<void> {
        const data = await kinetixFetch<{ views: KinetixSavedView[] }>(
            `${base()}/${view.id}/default`,
            { method: 'POST' },
        );
        views.value = data?.views ?? views.value;
    }

    return { views, loading, load, create, update, remove, setDefault };
}
