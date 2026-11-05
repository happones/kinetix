import { usePage } from '@inertiajs/vue3';
import { ref, toValue } from 'vue';
import type { MaybeRefOrGetter } from 'vue';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type { KinetixComment, KinetixSharedProps } from '@/types/kinetix';

/**
 * Self-service comments for a commentable model. The server returns the full
 * threaded tree after every mutation, so the list stays authoritative.
 *
 * Target the model with its sealed `reference` (from
 * `KinetixComments::reference($model)`), or with the legacy morph type + id.
 * When a reference is given it is the only target sent; it is read on every
 * request, so a getter keeps a reference refreshed by a reload current.
 */
export function useKinetixComments(
    commentableType?: string | null,
    commentableId?: number | string | null,
    reference?: MaybeRefOrGetter<string | null | undefined>,
) {
    const page = usePage<KinetixSharedProps>();
    const base = (): string => `/${kinetixRoutePrefix(page)}/comments`;

    const comments = ref<KinetixComment[]>([]);
    const loading = ref(false);

    const target = (): Record<string, string | number | null | undefined> => {
        const sealed = toValue(reference);

        return sealed
            ? { reference: sealed }
            : {
                  commentable_type: commentableType,
                  commentable_id: commentableId,
              };
    };

    async function load(): Promise<void> {
        loading.value = true;

        try {
            const q = new URLSearchParams();

            for (const [key, value] of Object.entries(target())) {
                q.set(key, String(value ?? ''));
            }

            const data = await kinetixFetch<{ comments: KinetixComment[] }>(
                `${base()}?${q.toString()}`,
            );
            comments.value = data?.comments ?? [];
        } finally {
            loading.value = false;
        }
    }

    async function post(
        text: string,
        parentId: number | string | null = null,
    ): Promise<void> {
        const data = await kinetixFetch<{ comments: KinetixComment[] }>(
            base(),
            {
                method: 'POST',
                body: { ...target(), body: text, parent_id: parentId },
            },
        );

        if (data?.comments) {
            comments.value = data.comments;
        }
    }

    async function edit(comment: KinetixComment, text: string): Promise<void> {
        await kinetixFetch(`${base()}/${comment.id}`, {
            method: 'PUT',
            body: { body: text },
        });
        await load();
    }

    async function remove(comment: KinetixComment): Promise<void> {
        await kinetixFetch(`${base()}/${comment.id}`, { method: 'DELETE' });
        await load();
    }

    return { comments, loading, load, post, edit, remove };
}
