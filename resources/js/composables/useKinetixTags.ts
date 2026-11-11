import { usePage } from '@inertiajs/vue3';
import { ref, toValue } from 'vue';
import type { MaybeRefOrGetter } from 'vue';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type { KinetixSharedProps } from '@/types/kinetix';

/**
 * Self-service polymorphic tags for a taggable model: load the current tags,
 * autocomplete from existing tags, and sync the set. The server returns the
 * authoritative tag list after a sync.
 *
 * Target the model with its sealed `reference` (from
 * `KinetixTags::reference($model)`), or with the legacy morph type + id. When a
 * reference is given it is the only target sent; it is read on every request,
 * so a getter keeps a reference refreshed by a reload current.
 */
export function useKinetixTags(
    taggableType?: string | null,
    taggableId?: number | string | null,
    reference?: MaybeRefOrGetter<string | null | undefined>,
) {
    const page = usePage<KinetixSharedProps>();
    const base = (): string => `/${kinetixRoutePrefix(page)}/tags`;

    const tags = ref<string[]>([]);
    const loading = ref(false);

    const target = (): Record<string, string | number | null | undefined> => {
        const sealed = toValue(reference);

        return sealed
            ? { reference: sealed }
            : { taggable_type: taggableType, taggable_id: taggableId };
    };

    async function load(): Promise<void> {
        loading.value = true;

        try {
            const q = new URLSearchParams();

            for (const [key, value] of Object.entries(target())) {
                q.set(key, String(value ?? ''));
            }

            const data = await kinetixFetch<{ tags: string[] }>(
                `${base()}?${q.toString()}`,
            );
            tags.value = data?.tags ?? [];
        } finally {
            loading.value = false;
        }
    }

    /**
     * Existing tags matching `query`. Pass `options.signal` to cancel a lookup
     * a newer keystroke has replaced.
     */
    async function suggest(
        query: string,
        options: { signal?: AbortSignal } = {},
    ): Promise<string[]> {
        const q = new URLSearchParams({ q: query });
        const data = await kinetixFetch<{ tags: string[] }>(
            `${base()}/suggest?${q.toString()}`,
            { signal: options.signal },
        );

        return data?.tags ?? [];
    }

    async function sync(next: string[]): Promise<void> {
        const data = await kinetixFetch<{ tags: string[] }>(`${base()}/sync`, {
            method: 'POST',
            body: { ...target(), tags: next },
        });
        tags.value = data?.tags ?? next;
    }

    return { tags, loading, load, suggest, sync };
}
