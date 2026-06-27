import { usePage } from "@inertiajs/vue3";
import { ref } from "vue";
import { kinetixFetch, kinetixRoutePrefix } from "@/composables/useKinetixHttp";
import type { KinetixComment, KinetixSharedProps } from "@/types";

/**
 * Self-service comments for a commentable model. The server returns the full
 * threaded tree after every mutation, so the list stays authoritative.
 */
export function useKinetixComments(
  commentableType: string,
  commentableId: number | string,
) {
  const page = usePage<KinetixSharedProps>();
  const base = (): string => `/${kinetixRoutePrefix(page)}/comments`;

  const comments = ref<KinetixComment[]>([]);
  const loading = ref(false);

  const body = () => ({
    commentable_type: commentableType,
    commentable_id: commentableId,
  });

  async function load(): Promise<void> {
    loading.value = true;
    try {
      const q = new URLSearchParams({
        commentable_type: commentableType,
        commentable_id: String(commentableId),
      });
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
    const data = await kinetixFetch<{ comments: KinetixComment[] }>(base(), {
      method: "POST",
      body: { ...body(), body: text, parent_id: parentId },
    });
    if (data?.comments) {
      comments.value = data.comments;
    }
  }

  async function edit(
    comment: KinetixComment,
    text: string,
  ): Promise<void> {
    await kinetixFetch(`${base()}/${comment.id}`, {
      method: "PUT",
      body: { body: text },
    });
    await load();
  }

  async function remove(comment: KinetixComment): Promise<void> {
    await kinetixFetch(`${base()}/${comment.id}`, { method: "DELETE" });
    await load();
  }

  return { comments, loading, load, post, edit, remove };
}
