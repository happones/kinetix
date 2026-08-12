import { usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type { KinetixAnnouncement, KinetixSharedProps } from '@/types/kinetix';

/**
 * Self-service "what's new" feed: load published announcements + the unread
 * count, and mark the feed seen (clearing the unread badge).
 */
export function useKinetixAnnouncements() {
    const base = useAnnouncementsBase();

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

export interface KinetixAnnouncementBannerOptions {
    /** How many entries to rotate through (server ceiling: 10). */
    limit?: number;
    /** Restrict to these levels; empty = every level. */
    levels?: string[];
}

/**
 * The banner feed: published entries the user hasn't dismissed yet. Unlike the
 * "what's new" popover, dismissing is per announcement — closing one banner
 * hides that entry for good instead of marking the whole feed read.
 */
export function useKinetixAnnouncementBanner(
    options: KinetixAnnouncementBannerOptions = {},
) {
    const base = useAnnouncementsBase();

    const announcements = ref<KinetixAnnouncement[]>([]);
    const loading = ref(false);

    async function load(): Promise<void> {
        loading.value = true;

        const query = new URLSearchParams();

        if (options.limit) {
            query.set('limit', String(options.limit));
        }

        if (options.levels?.length) {
            query.set('levels', options.levels.join(','));
        }

        const search = query.toString();
        const suffix = search === '' ? '' : `?${search}`;

        try {
            const data = await kinetixFetch<{
                announcements: KinetixAnnouncement[];
            }>(`${base()}/banner${suffix}`);
            announcements.value = data?.announcements ?? [];
        } finally {
            loading.value = false;
        }
    }

    /**
     * Hide one entry. Removed locally first so the banner reacts instantly, and
     * restored if the server rejects it.
     */
    async function dismiss(announcement: KinetixAnnouncement): Promise<void> {
        const previous = announcements.value;
        announcements.value = previous.filter((a) => a.id !== announcement.id);

        try {
            await kinetixFetch(`${base()}/${announcement.id}/dismiss`, {
                method: 'POST',
            });
        } catch (error) {
            announcements.value = previous;

            throw error;
        }
    }

    return { announcements, loading, load, dismiss };
}

/**
 * Presentation shared by the popover and the banner: level colours, the
 * translated level label, and dates in the app's language rather than the
 * browser's.
 */
export function useKinetixAnnouncementFormat() {
    const { t, te, locale } = useI18n();

    const levelClasses: Record<string, string> = {
        feature: 'bg-success/15 text-success',
        fix: 'bg-info/15 text-info',
        info: 'bg-muted text-muted-foreground',
    };

    /** Levels are host-defined, so an unknown one falls back to the slug. */
    function levelLabel(level: string): string {
        const key = `kinetix.announcements_level_${level}`;

        return te(key) ? t(key) : level;
    }

    function levelClass(level: string): string {
        return levelClasses[level] ?? levelClasses.info;
    }

    function formatDate(value: string | null): string {
        return value
            ? new Date(value).toLocaleDateString(locale.value as string)
            : '';
    }

    return { levelClass, levelLabel, formatDate };
}

function useAnnouncementsBase(): () => string {
    const page = usePage<KinetixSharedProps>();

    return (): string => `/${kinetixRoutePrefix(page)}/announcements`;
}
