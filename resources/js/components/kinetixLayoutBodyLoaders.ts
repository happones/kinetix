import { defineComponent, h, markRaw } from 'vue';
import {
    defineKinetixLazy,
    KinetixLazyBlockSkeleton,
    KinetixLazyFieldSkeleton,
} from '@/composables/kinetixLazy';

/**
 * The heavy bodies of the layout hosts (the components a host app mounts once
 * in its layout), each behind a dynamic import so a page only downloads the
 * ones it actually uses. Module scope: one download and one loaded component
 * for every mounted host. Nothing here imports a body statically.
 */

/** The import wizard: the dialog opens at once on a busy skeleton. */
export const KINETIX_IMPORTER_BODY = defineKinetixLazy(
    () => import('./KinetixImporter.vue'),
    { loading: KinetixLazyBlockSkeleton },
);

/** The lightbox viewer: loaded on the first opening, or on intent. */
export const KINETIX_LIGHTBOX_BODY = defineKinetixLazy(
    () => import('./KinetixLightbox.vue'),
    { loading: false },
);

/** The command palette: loaded on the first Cmd/Ctrl+K. */
export const KINETIX_SPOTLIGHT_BODY = defineKinetixLazy(
    () => import('./Spotlight/SpotlightBody.vue'),
    { loading: false },
);

/** The keyboard-shortcuts sheet: loaded on the first `?`. */
export const KINETIX_SHORTCUTS_BODY = defineKinetixLazy(
    () => import('./Shortcuts/ShortcutsBody.vue'),
    { loading: false },
);

/** The tour driver: loaded once the page shares tours. */
export const KINETIX_TOURS_BODY = defineKinetixLazy(
    () => import('./Tours/ToursBody.vue'),
    { loading: false },
);

/** The announcements banner: loaded once there may be an entry to show. */
export const KINETIX_ANNOUNCEMENT_BANNER_BODY = defineKinetixLazy(
    () => import('./Announcements/AnnouncementBannerBody.vue'),
    { loading: false },
);

/**
 * Holds the header slot at the size of the lock button while the widget's
 * code arrives, so the header doesn't shift.
 */
const IconButtonPlaceholder = markRaw(
    defineComponent({
        name: 'KinetixIconButtonPlaceholder',
        setup: () => () =>
            h('span', {
                class: 'inline-block size-8 shrink-0',
                'aria-hidden': 'true',
                'data-kinetix-lazy-skeleton': 'icon-button',
            }),
    }),
);

/** The confidential reveal-gate widget: loaded when confidential fields are on. */
export const KINETIX_CONFIDENTIAL_UNLOCK_BODY = defineKinetixLazy(
    () => import('./Confidential/ConfidentialUnlockBody.vue'),
    { loading: IconButtonPlaceholder },
);

/** The cookie consent bar: loaded only for a visitor who still has to choose. */
export const KINETIX_COOKIE_CONSENT_BODY = defineKinetixLazy(
    () => import('./CookieConsent/CookieConsentBody.vue'),
    { loading: false },
);

/**
 * The language switcher's `select` variant (a form field, with the Select
 * primitive behind it); the header's default dropdown stays static.
 */
export const KINETIX_LANGUAGE_SELECT_BODY = defineKinetixLazy(
    () => import('./LanguageSwitcher/LanguageSelect.vue'),
    { loading: KinetixLazyFieldSkeleton },
);
