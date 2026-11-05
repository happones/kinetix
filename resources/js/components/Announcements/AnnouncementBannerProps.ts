import type { KinetixDismissMode } from '@/composables/useKinetixDismissal';
import type { KinetixAlertVariant } from '@/composables/useKinetixStatusColor';
import type { KinetixTransitionPreset } from '@/composables/useKinetixTransition';

/**
 * The props of `<KinetixAnnouncementBanner>`, shared by the layout host and
 * the banner body it loads.
 */
export interface KinetixAnnouncementBannerProps {
    /**
     * How many entries to rotate through (server ceiling: 10). Unset, it is
     * `announcements.banner_limit` — the shape the page payload carries.
     */
    limit?: number;
    /** Only show these levels; empty = every level. */
    levels?: string[];
    /** Rotation interval in ms; `0` turns auto-rotation off. */
    autoplay?: number;
    /** Show the close button. */
    dismissible?: boolean;
    /**
     * What the ✕ does: `permanent` (the account, every device), `device`
     * (this browser), `session` (this tab) or `hide` (until unmount).
     */
    dismissMode?: KinetixDismissMode;
    /** `session`/`device`: ms until a closed entry comes back. */
    dismissDuration?: number | null;
    /** Add a "Don't show again" link that closes the entry for good. */
    dontShowAgain?: boolean;
    /**
     * `inline` sits in the page flow; `fixed-top` pins the banner to the
     * top of the viewport, above the page and below Kinetix's overlays.
     */
    position?: 'inline' | 'fixed-top';
    /** Max width of the pinned bar (any Tailwind width class). */
    fixedWidthClass?: string;
    /**
     * `plain` keeps the neutral surface; `soft`, `outline` and `accent`
     * color it with each entry's level (`kinetix.announcements.levels`).
     * No `solid`: the carousel's dots and arrows are drawn for a light
     * surface.
     */
    variant?: 'plain' | Exclude<KinetixAlertVariant, 'solid'>;
    /**
     * How the banner enters and leaves. Unset = `slide-down` when pinned,
     * `fade` inline.
     */
    transition?: KinetixTransitionPreset;
    /** How one entry gives way to the next while rotating. */
    slideTransition?: KinetixTransitionPreset;
    /**
     * Where the carousel is: `dots` (one button per entry), `counter`
     * ("2 / 7") or `auto` — dots while the controls have room for all of
     * them, the counter on a narrow banner (a phone).
     */
    indicators?: 'auto' | 'dots' | 'counter';
    class?: string;
}

export const KINETIX_ANNOUNCEMENT_BANNER_DEFAULTS = {
    // No default limit: a hardcoded one would override the config and
    // force a fetch on every mount whenever the two disagree.
    limit: undefined,
    autoplay: 8000,
    dismissible: true,
    dismissMode: 'permanent',
    dismissDuration: null,
    dontShowAgain: false,
    position: 'inline',
    fixedWidthClass: 'max-w-3xl',
    variant: 'plain',
    transition: undefined,
    slideTransition: 'fade',
    indicators: 'auto',
} satisfies Partial<KinetixAnnouncementBannerProps>;
