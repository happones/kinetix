import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type {
    KinetixAnnouncement,
    KinetixEditableAnnouncement,
    KinetixSharedProps,
} from '@/types/kinetix';

/**
 * Self-service "what's new" feed: load published announcements + the unread
 * count, and mark the feed seen (clearing the unread badge).
 */
export function useKinetixAnnouncements() {
    const page = usePage<KinetixSharedProps>();
    const base = useAnnouncementsBase();

    const announcements = ref<KinetixAnnouncement[]>([]);
    const loading = ref(false);
    const loaded = ref(false);

    /**
     * The badge comes from the page payload, so the header costs no request at
     * all until someone opens the feed. `seen` is optimistic: it wins over the
     * prop until the next Inertia response carries the cleared count.
     */
    const seen = ref(false);
    const unread = computed(() =>
        seen.value ? 0 : (page.props.kinetix_announcements?.unread ?? 0),
    );

    async function load(): Promise<void> {
        loading.value = true;

        try {
            const data = await kinetixFetch<{
                announcements: KinetixAnnouncement[];
                unread: number;
            }>(base());
            announcements.value = data?.announcements ?? [];
            loaded.value = true;
        } finally {
            loading.value = false;
        }
    }

    /** Fetch the list once — the badge alone doesn't need it. */
    async function loadOnce(): Promise<void> {
        if (!loaded.value && !loading.value) {
            await load();
        }
    }

    async function markSeen(): Promise<void> {
        seen.value = true;
        await kinetixFetch(`${base()}/seen`, { method: 'POST' });
    }

    return { announcements, unread, loading, load, loadOnce, markSeen };
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
    const page = usePage<KinetixSharedProps>();
    const base = useAnnouncementsBase();

    const announcements = ref<KinetixAnnouncement[]>([]);
    const loading = ref(false);

    /**
     * The page payload carries the default banner feed, so an un-narrowed
     * banner costs no request. Narrow it — different levels, a different limit
     * — and only the server can answer.
     */
    function shared(): KinetixAnnouncement[] | null {
        const state = page.props.kinetix_announcements;

        if (!state || options.levels?.length) {
            return null;
        }

        return options.limit === undefined ||
            options.limit === state.bannerLimit
            ? state.banner
            : null;
    }

    async function load(): Promise<void> {
        const hydrated = shared();

        if (hydrated !== null) {
            announcements.value = hydrated;

            return;
        }

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
 * Authoring: the full list (drafts and scheduled entries included), create,
 * update and delete. Every call is gated server-side by
 * `manageKinetixAnnouncements`.
 */
export function useKinetixAnnouncementManager() {
    const base = useAnnouncementsBase();

    const announcements = ref<KinetixEditableAnnouncement[]>([]);
    /** Inside a team, platform-wide entries are read-only. */
    const teamScoped = ref(false);
    const loading = ref(false);

    async function load(): Promise<void> {
        loading.value = true;

        try {
            const data = await kinetixFetch<{
                announcements: KinetixEditableAnnouncement[];
                teamScoped: boolean;
            }>(`${base()}/manage`);
            announcements.value = data?.announcements ?? [];
            teamScoped.value = data?.teamScoped ?? false;
        } finally {
            loading.value = false;
        }
    }

    async function save(
        announcement: KinetixEditableAnnouncement,
    ): Promise<KinetixEditableAnnouncement | null> {
        const isUpdate = announcement.id != null;
        const res = await kinetixFetch<{
            announcement: KinetixEditableAnnouncement;
        }>(isUpdate ? `${base()}/${announcement.id}` : base(), {
            method: isUpdate ? 'PUT' : 'POST',
            body: {
                title: announcement.title,
                body: announcement.body,
                level: announcement.level,
                // The API speaks the column name; `null` is a draft.
                published_at: announcement.publishedAt,
            },
        });

        await load();

        return res?.announcement ?? null;
    }

    async function remove(id: number | string): Promise<void> {
        await kinetixFetch(`${base()}/${id}`, { method: 'DELETE' });
        await load();
    }

    return { announcements, teamScoped, loading, load, save, remove };
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
