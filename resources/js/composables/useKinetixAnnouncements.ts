import { usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type { KinetixAnnouncement, KinetixSharedProps } from '@/types';

/**
 * Self-service "what's new" feed: load published announcements + the unread
 * count, and mark the feed seen (clearing the unread badge).
 */
export function useKinetixAnnouncements() {
    const page = usePage<KinetixSharedProps>();
    const base = (): string => `/${kinetixRoutePrefix(page)}/announcements`;

    const announcements = ref<KinetixAnnouncement[]>([]);
    const unread = ref(0);
    const loading = ref(false);

    async function load(): Promise<void> {
        loading.value = true;

        try {
            const data = await kinetixFetch<{
                announcements: KinetixAnnouncement[];
                unread: number;
            }>(base());
            announcements.value = data?.announcements ?? [];
            unread.value = data?.unread ?? 0;
        } finally {
            loading.value = false;
        }
    }

    async function markSeen(): Promise<void> {
        unread.value = 0;
        await kinetixFetch(`${base()}/seen`, { method: 'POST' });
    }

    return { announcements, unread, loading, load, markSeen };
}
