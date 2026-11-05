<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { useKinetixLazyWhenEnabled } from '@/composables/useKinetixLazyHost';
import type { KinetixSharedProps } from '@/types/kinetix';
import { KINETIX_ANNOUNCEMENT_BANNER_DEFAULTS } from './Announcements/AnnouncementBannerProps';
import type { KinetixAnnouncementBannerProps } from './Announcements/AnnouncementBannerProps';
import { KINETIX_ANNOUNCEMENT_BANNER_BODY } from './kinetixLayoutBodyLoaders';

/**
 * Announcements as an inline banner instead of a header popover: one entry at a
 * time, rotating through the rest when there is more than one. Dropping it at
 * the top of a page (or a layout) puts the message where the work happens,
 * which the megaphone icon can't do.
 *
 * Closing is per entry and lasts as long as `dismissMode` says — for good on
 * every device by default; `dontShowAgain` lets the ✕ hide it for now while a
 * link closes it for good.
 *
 * The banner itself downloads only once the page may have something for it:
 * the shared feed holds an entry, or only the server can answer (the feed is
 * not shared, or the props narrow it to other levels or another limit). An
 * empty shared feed loads nothing until a later response brings an entry.
 */
defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<KinetixAnnouncementBannerProps>(),
    KINETIX_ANNOUNCEMENT_BANNER_DEFAULTS,
);

const page = usePage<KinetixSharedProps>();
const { active, component: Body } = useKinetixLazyWhenEnabled(
    KINETIX_ANNOUNCEMENT_BANNER_BODY,
    () => {
        const state = page.props.kinetix_announcements;

        // Not shared (`announcements.share` off): the banner fetches its own.
        if (!state) {
            return true;
        }

        const narrowed =
            (props.levels?.length ?? 0) > 0 ||
            (props.limit !== undefined && props.limit !== state.bannerLimit);

        return narrowed || (state.banner?.length ?? 0) > 0;
    },
);
</script>

<template>
    <component :is="Body" v-if="active" v-bind="{ ...$props, ...$attrs }" />
</template>
