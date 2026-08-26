<script setup lang="ts">
import type { KinetixPageData } from '@/types/kinetix';
import KinetixPageFooter from './KinetixPageFooter.vue';
import KinetixPageHeader from './KinetixPageHeader.vue';

/**
 * Renders a page declared in PHP with `Happones\Kinetix\Pages\Page`: its header
 * bar, then whatever you put in the default slot, then its footer bar.
 *
 *     <KinetixPageShell :page="page">
 *         <MyCustomThing />
 *     </KinetixPageShell>
 *
 * It is pure composition over `KinetixPageHeader` + `KinetixPageFooter`, and it
 * exists only to save unpacking `page.heading` / `page.headerActions` /
 * `page.footerActions` by hand. Composing the two bars yourself remains fully
 * supported — reach for that when the page needs something between them that
 * this shell's slots don't cover.
 *
 * Each bar renders only when it has something to show, so a page with no footer
 * actions gets no empty bar (pass `alwaysFooter` when a footer slot supplies the
 * content instead of actions).
 *
 * Slots: `default` (the page body), `header-before-actions` / `header-actions`
 * and `footer-before-actions` / `footer-actions` — the corresponding slots of
 * the two bars.
 */
const props = withDefaults(
    defineProps<{
        page: KinetixPageData;
        /**
         * Render the footer bar even with no actions — for a footer whose
         * content comes entirely from a slot.
         */
        alwaysFooter?: boolean;
    }>(),
    { alwaysFooter: false },
);

const hasHeader = (): boolean =>
    !!props.page.heading ||
    !!props.page.description ||
    (props.page.headerActions?.length ?? 0) > 0;

const hasFooter = (): boolean =>
    props.alwaysFooter || (props.page.footerActions?.length ?? 0) > 0;
</script>

<template>
    <div class="min-w-0 flex flex-col">
        <KinetixPageHeader
            v-if="hasHeader() || $slots['header-actions']"
            :heading="page.heading"
            :description="page.description"
            :actions="page.headerActions"
        >
            <template v-if="$slots['header-before-actions']" #before-actions>
                <slot name="header-before-actions" />
            </template>
            <template v-if="$slots['header-actions']" #default>
                <slot name="header-actions" />
            </template>
        </KinetixPageHeader>

        <slot />

        <KinetixPageFooter
            v-if="hasFooter() || $slots['footer-actions']"
            :actions="page.footerActions"
            :sticky="page.stickyFooter"
        >
            <template v-if="$slots['footer-before-actions']" #before-actions>
                <slot name="footer-before-actions" />
            </template>
            <template v-if="$slots['footer-actions']" #default>
                <slot name="footer-actions" />
            </template>
        </KinetixPageFooter>
    </div>
</template>
