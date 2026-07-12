<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import {
    computeMasonryLayout,
    gapToPx,
    packIntoColumns,
    resolveResponsiveValue,
} from '@/composables/useMasonryColumns';
import type { KinetixWidget } from '@/types';

/**
 * Column-balanced masonry: distributes `widgets` into a responsive number of
 * columns, greedily placing each into whichever column is currently
 * shortest (measured via `ResizeObserver`), eliminating the height gaps a
 * plain CSS grid leaves when row-mates have different heights.
 *
 * Each widget occupies exactly one column — there's no `columnSpan` concept
 * here, unlike the standard grid layout. Rendering itself is left to the
 * caller via the `#item` scoped slot, so this component owns only the
 * packing/measuring logic (see `useMasonryColumns` for the pure algorithm).
 *
 * Every widget renders exactly once, in a single flat `v-for` keyed by
 * `widget.id` — its column/position is applied via CSS (`grid-column` +
 * `top`), never by moving the node into a different `v-for` array. A CSS
 * grid column determines the item's horizontal "grid area", and since the
 * item is `position: absolute`, that grid area becomes its containing block
 * — so `top: Npx` positions it vertically within its column without any
 * manual width/left math. This is what lets a widget move between columns
 * as heights settle without ever being unmounted/remounted.
 */
const props = defineProps<{
    widgets: KinetixWidget[];
    columns: number | string | Record<string, number | string>;
    gap: number | string | Record<string, number | string>;
}>();

const viewportWidth = ref(
    typeof window === 'undefined' ? 1280 : window.innerWidth,
);

function onResize(): void {
    viewportWidth.value = window.innerWidth;
}

onMounted(() => {
    window.addEventListener('resize', onResize, { passive: true });
});
onBeforeUnmount(() => {
    window.removeEventListener('resize', onResize);
});

const columnCount = computed(() => {
    const resolved = Number(
        resolveResponsiveValue(props.columns, viewportWidth.value),
    );

    return Math.max(1, Number.isFinite(resolved) ? resolved : 1);
});

const gapValue = computed(() => {
    const resolved = resolveResponsiveValue(props.gap, viewportWidth.value);

    return typeof resolved === 'number' ? `${resolved}px` : resolved;
});

const gapPx = computed(() => gapToPx(gapValue.value));

// Measured height (px) per widget id, updated as each item mounts/resizes.
const heights = reactive<Record<string, number>>({});
const observers = new Map<string, ResizeObserver>();

function observe(el: Element | null, id: string): void {
    const existing = observers.get(id);

    if (existing) {
        existing.disconnect();
        observers.delete(id);
    }

    if (!el || typeof ResizeObserver === 'undefined') {
        return;
    }

    const ro = new ResizeObserver(([entry]) => {
        if (entry) {
            heights[id] = entry.contentRect.height;
        }
    });
    ro.observe(el);
    observers.set(id, ro);
}

onBeforeUnmount(() => {
    observers.forEach((ro) => ro.disconnect());
    observers.clear();
});

const columnsOf = computed<KinetixWidget[][]>(() =>
    packIntoColumns(props.widgets, columnCount.value, heights),
);

const layout = computed(() =>
    computeMasonryLayout(columnsOf.value, heights, gapPx.value),
);

function itemStyle(id: string): Record<string, string> {
    const position = layout.value.positions[id];
    const column = position ? position.column + 1 : 1;

    // Both the start AND end line must be explicit — a bare `grid-column:
    // N` only sets grid-column-start (end stays `auto`), which per the CSS
    // Grid spec leaves an absolutely-positioned item's grid area
    // indeterminate for containing-block purposes, so its `width: 100%`
    // resolves against the whole grid container instead of its column,
    // causing every item to overlap at full width.
    return {
        gridColumn: `${column} / span 1`,
        top: `${position ? position.top : 0}px`,
    };
}
</script>

<template>
    <div
        class="relative grid w-full"
        :style="{
            gridTemplateColumns: `repeat(${columnCount}, minmax(0, 1fr))`,
            columnGap: gapValue,
            height: `${layout.containerHeight}px`,
        }"
    >
        <div
            v-for="widget in widgets"
            :key="widget.id"
            :ref="(el) => observe(el as Element | null, widget.id)"
            class="left-0 absolute w-full"
            :style="itemStyle(widget.id)"
        >
            <slot name="item" :widget="widget" />
        </div>
    </div>
</template>
