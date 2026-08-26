<script setup lang="ts">
import { computed } from 'vue';
import type { KinetixAction } from '@/types/kinetix';
import KinetixActionBar from './KinetixActionBar.vue';

/**
 * A page-level footer action bar — the counterpart to `KinetixPageHeader`, for
 * the actions that belong at the END of a page ("Save", "Cancel", "Submit for
 * review", "Archive").
 *
 * Like the header, it knows nothing about what the page renders above it: pass
 * serialized `KinetixAction`s and put whatever you like in between. Both bars
 * render through the same `KinetixActionBar`, so a confirmation, a grouped
 * dropdown, a declared shortcut or a pending spinner behaves the same top and
 * bottom.
 *
 * `sticky` pins the bar to the bottom of the scroll container with the shadcn
 * footer chrome (top border, solid background) — for a long page where "Save"
 * should stay reachable without scrolling to the end. It uses `position: sticky`
 * rather than `fixed` ON PURPOSE: the bar stays part of the layout, so it never
 * covers the last of the content the way a fixed bar does.
 *
 * The action row is `flex-col-reverse` on mobile, which puts the LAST action —
 * the primary one, by convention — on top where the thumb is, and a
 * right-aligned row from `sm` up. Same rule as the dialog shells' footers.
 *
 * Slots: `before-actions` (left of the row — a save state, a hint, a validation
 * summary) and the default slot (right of it).
 */
const props = withDefaults(
    defineProps<{
        actions?: KinetixAction[];
        /** Pin the bar to the bottom of the scroll container. */
        sticky?: boolean;
        /**
         * Register the actions' declared keyboard shortcuts. Off by default:
         * a footer usually repeats actions the header already bound, and two
         * handlers for one chord is a bug, not a feature.
         */
        shortcuts?: boolean;
    }>(),
    {
        actions: () => [],
        sticky: false,
        shortcuts: false,
    },
);

const wrapperClass = computed(() =>
    props.sticky
        ? // `-mx-4 px-4` bleeds the bar's background to the page gutter, so
          // content scrolling underneath is covered rather than peeking past it.
          'bottom-0 z-10 -mx-4 mt-6 px-4 py-4 sticky border-t border-border bg-background'
        : 'mt-6',
);
</script>

<template>
    <div
        class="gap-3 sm:flex-row sm:items-center sm:justify-between flex flex-col"
        :class="wrapperClass"
    >
        <!-- Left side: present only when something is in it, so the actions stay
             right-aligned on their own. -->
        <div
            v-if="$slots['before-actions']"
            class="min-w-0 text-sm text-muted-foreground"
        >
            <slot name="before-actions" />
        </div>

        <KinetixActionBar
            :actions="actions"
            :shortcuts="shortcuts"
            stack
            class="sm:ml-auto"
        >
            <template #after>
                <slot />
            </template>
        </KinetixActionBar>
    </div>
</template>
