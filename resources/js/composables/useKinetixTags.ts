import { usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type { KinetixSharedProps } from '@/types/kinetix';

/**
 * Self-service polymorphic tags for a taggable model: load the current tags,
 * autocomplete from existing tags, and sync the set. The server returns the
 * authoritative tag list after a sync.
 */
export function useKinetixTags(
    taggableType: string,
    taggableId: number | string,
) {
    const page = usePage<KinetixSharedProps>();
    const base = (): string => `/${kinetixRoutePrefix(page)}/tags`;

    const tags = ref<string[]>([]);
    const loading = ref(false);

    async function load(): Promise<void> {
        loading.value = true;

        try {
            const q = new URLSearchParams({
                taggable_type: taggableType,
                taggable_id: String(taggableId),
            });
            const data = await kinetixFetch<{ tags: string[] }>(
                `${base()}?${q.toString()}`,
            );
            tags.value = data?.tags ?? [];
        } finally {
            loading.value = false;
        }
    }

    async function suggest(query: string): Promise<string[]> {
        const q = new URLSearchParams({ q: query });
        const data = await kinetixFetch<{ tags: string[] }>(
            `${base()}/suggest?${q.toString()}`,
        );

        return data?.tags ?? [];
    }

    async function sync(next: string[]): Promise<void> {
        const data = await kinetixFetch<{ tags: string[] }>(`${base()}/sync`, {
            method: 'POST',
            body: {
                taggable_type: taggableType,
                taggable_id: taggableId,
                tags: next,
            },
        });
        tags.value = data?.tags ?? next;
    }

    return { tags, loading, load, suggest, sync };
}
