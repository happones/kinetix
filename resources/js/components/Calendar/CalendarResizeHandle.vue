<script setup lang="ts">
import type { KinetixCalendarResizeAxis } from '@/composables/useKinetixCalendarEventResize';

/**
 * The grab strip on an event's end edge: the bottom of a timed event in the
 * hour grids, the right end of a day chip. A pointer affordance only — the
 * keyboard alternative is Alt+Shift+arrows on the event itself — so it is
 * hidden from assistive tech. Its own click never reaches the event, so
 * letting go of a resize doesn't open the event's details. The grip shows on
 * hover and focus, and always on touch screens, where the strip is taller.
 */
defineProps<{
    /** `time`: dragged down an hour grid; `day`: across day cells. */
    axis: KinetixCalendarResizeAxis;
}>();

const emit = defineEmits<{
    (e: 'start', event: PointerEvent): void;
}>();
</script>

<template>
    <span
        aria-hidden="true"
        data-calendar-resize
        class="absolute flex touch-none items-center justify-center"
        :class="
            axis === 'time'
                ? 'inset-x-0 bottom-0 h-2 pointer-coarse:h-4 cursor-ns-resize'
                : 'inset-y-0 right-0 w-2 pointer-coarse:w-4 cursor-ew-resize'
        "
        @pointerdown="emit('start', $event)"
        @click.stop.prevent
    >
        <span
            class="bg-white/80 rounded-full opacity-0 transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100 pointer-coarse:opacity-100"
            :class="axis === 'time' ? 'h-0.5 w-4' : 'h-2.5 w-0.5'"
        />
    </span>
</template>
