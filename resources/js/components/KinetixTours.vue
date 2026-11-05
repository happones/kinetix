<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { useKinetixLazyWhenEnabled } from '@/composables/useKinetixLazyHost';
import type { KinetixSharedProps } from '@/types/kinetix';
import { KINETIX_TOURS_BODY } from './kinetixLayoutBodyLoaders';

/**
 * The global product-tour host. Mount ONE instance in your app layout:
 *
 *     <KinetixTours />
 *
 * On every Inertia navigation it looks for an unseen, auto-start tour whose
 * `page`/`url` pattern matches, and drives it with driver.js (spotlight
 * overlay, auto-scroll, collision-aware popover) themed to the shadcn tokens.
 * Manual launches go through the tours pinia store:
 * `useKinetixToursStore().start('posts')`.
 *
 * The tour driver only downloads once the page shares tours (the module is on
 * and at least one tour is registered). The default slot renders here, always,
 * next to the (renderless) driver.
 *
 * driver.js is an opt-in host dependency (`kinetix:install --tours`); without
 * it the host warns once in the console and tours simply don't render.
 */
defineOptions({ inheritAttrs: false });

const page = usePage<KinetixSharedProps>();
const { active, component: Body } = useKinetixLazyWhenEnabled(
    KINETIX_TOURS_BODY,
    () =>
        page.props.kinetix_tours?.enabled === true &&
        (page.props.kinetix_tours.tours?.length ?? 0) > 0,
);
</script>

<template>
    <!-- The slot stays the host's own: it never remounts while the tour
         driver loads, or when tours are switched on later. -->
    <slot />
    <component :is="Body" v-if="active" v-bind="$attrs" />
</template>
