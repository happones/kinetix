import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixDismissalStore } from '@/composables/useKinetixDismissal';
import type { KinetixDismissMode } from '@/composables/useKinetixDismissal';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import { statusBadgeClass } from '@/composables/useKinetixStatusColor';
import type { KinetixStatusColor } from '@/composables/useKinetixStatusColor';
import type {
    KinetixAnnouncement,
    KinetixAnnouncementLevelOption,
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
    /**
     * How many entries to rotate through (server ceiling: 10). Unset, the
     * server's `announcements.banner_limit` applies.
     */
    limit?: number;
    /** Restrict to these levels; empty = every level. */
    levels?: string[];
}

/**
 * The banner feed: published entries the user hasn't closed. Unlike the
 * "what's new" popover, closing is per announcement, and it lasts as long as
 * the caller says (`dismiss(entry, mode)`):
 *
 * - `permanent` (default) — server-side, for the account, on every device;
 * - `device` / `session` — this browser / this tab, optionally for a while;
 * - `hide` — until this banner unmounts.
 *
 * The page payload lives in the browser history, so Back/Forward would bring
 * a closed entry back with the page it was on. Every close is also written to
 * the tab's dismissal ledger, and every payload is filtered through it.
 */
export function useKinetixAnnouncementBanner(
    options: KinetixAnnouncementBannerOptions = {},
) {
    const page = usePage<KinetixSharedProps>();
    const base = useAnnouncementsBase();
    const ledger = useKinetixDismissalStore();

    /** Ids closed with `hide` — this instance's memory, nobody else's. */
    const hiddenHere = new Set<string>();

    const keyOf = (announcement: KinetixAnnouncement): string =>
        `announcement:${announcement.id}`;

    function stillOpen(list: KinetixAnnouncement[]): KinetixAnnouncement[] {
        return list.filter(
            (a) =>
                !hiddenHere.has(String(a.id)) && !ledger.isDismissed(keyOf(a)),
        );
    }

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

    // Hydrated during setup, not on mount: the first render already holds the
    // entries, so a page load neither replays the enter transition nor shifts
    // the layout once the banner pops in.
    const announcements = ref<KinetixAnnouncement[]>(stillOpen(shared() ?? []));
    const loading = ref(false);

    // A persistent layout keeps this banner across visits; follow the payload
    // each response ships instead of freezing on the first one.
    watch(
        () => page.props.kinetix_announcements,
        () => {
            const hydrated = shared();

            if (hydrated !== null) {
                announcements.value = stillOpen(hydrated);
            }
        },
    );

    async function load(): Promise<void> {
        const hydrated = shared();

        if (hydrated !== null) {
            announcements.value = stillOpen(hydrated);

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
            announcements.value = stillOpen(data?.announcements ?? []);
        } finally {
            loading.value = false;
        }
    }

    /**
     * Close one entry. Removed locally first so the banner reacts instantly;
     * a `permanent` close the server rejects is restored (and re-thrown).
     * `duration` (ms) makes a `session`/`device` close lapse on its own.
     */
    async function dismiss(
        announcement: KinetixAnnouncement,
        mode: KinetixDismissMode = 'permanent',
        duration: number | null = null,
    ): Promise<void> {
        const previous = announcements.value;
        announcements.value = previous.filter((a) => a.id !== announcement.id);

        if (mode === 'hide') {
            hiddenHere.add(String(announcement.id));

            return;
        }

        if (mode === 'session' || mode === 'device') {
            ledger.remember(keyOf(announcement), mode, duration);

            return;
        }

        try {
            await kinetixFetch(`${base()}/${announcement.id}/dismiss`, {
                method: 'POST',
            });
        } catch (error) {
            announcements.value = previous;

            throw error;
        }

        ledger.remember(keyOf(announcement), 'session');
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
    /** What the level picker offers (`kinetix.announcements.levels`). */
    const levels = ref<KinetixAnnouncementLevelOption[]>([]);
    const loading = ref(false);

    async function load(): Promise<void> {
        loading.value = true;

        try {
            const data = await kinetixFetch<{
                announcements: KinetixEditableAnnouncement[];
                teamScoped: boolean;
                levels?: KinetixAnnouncementLevelOption[];
            }>(`${base()}/manage`);
            announcements.value = data?.announcements ?? [];
            teamScoped.value = data?.teamScoped ?? false;
            levels.value = data?.levels ?? [];
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
                // The API speaks the column names; `null` published is a
                // draft, `null` expiry never expires.
                published_at: announcement.publishedAt,
                expires_at: announcement.expiresAt ?? null,
                dismissible: announcement.dismissible ?? true,
                // A button needs both halves; a blank one is no button.
                action_label: announcement.actionLabel?.trim() || null,
                action_url: announcement.actionUrl?.trim() || null,
            },
        });

        await load();

        return res?.announcement ?? null;
    }

    async function remove(id: number | string): Promise<void> {
        await kinetixFetch(`${base()}/${id}`, { method: 'DELETE' });
        await load();
    }

    return { announcements, teamScoped, levels, loading, load, save, remove };
}

/**
 * Presentation shared by the popover and the banner: level colors, the
 * translated level label, and dates in the app's language rather than the
 * browser's.
 */
export function useKinetixAnnouncementFormat() {
    const { t, te, locale } = useI18n();

    /** Levels on the shared status palette; unknown levels read neutral. */
    const levelColors: Record<string, KinetixStatusColor> = {
        feature: 'success',
        fix: 'info',
        info: 'gray',
    };

    /** Levels are host-defined, so an unknown one falls back to the slug. */
    function levelLabel(level: string): string {
        const key = `kinetix.announcements_level_${level}`;

        return te(key) ? t(key) : level;
    }

    /**
     * The entry's color: the server resolves it from config when it can (pass
     * the entry's `color`); the built-in levels are the fallback.
     */
    function levelColor(
        level: string,
        resolved?: string | null,
    ): KinetixStatusColor {
        return resolved || (levelColors[level] ?? 'gray');
    }

    /** The level pill — the shared soft-badge recipe in the level's color. */
    function levelClass(level: string): string {
        return statusBadgeClass(levelColor(level));
    }

    function formatDate(value: string | null): string {
        return value
            ? new Date(value).toLocaleDateString(locale.value as string)
            : '';
    }

    return { levelColor, levelClass, levelLabel, formatDate };
}

function useAnnouncementsBase(): () => string {
    const page = usePage<KinetixSharedProps>();

    return (): string => `/${kinetixRoutePrefix(page)}/announcements`;
}
