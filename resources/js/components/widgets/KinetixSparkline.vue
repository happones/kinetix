<script setup lang="ts">
import { computed, useId } from 'vue';

/**
 * A tiny inline trend line (area + stroke). Color comes from `currentColor`,
 * so the parent picks the tint with a text class (e.g. `text-success`) and the
 * sparkline follows the theme tokens in light/dark automatically.
 */
const props = withDefaults(
    defineProps<{
        data: number[];
        width?: number;
        height?: number;
    }>(),
    { width: 120, height: 40 },
);

// Gradient defs are inlined per instance; useId keeps IDs unique (and
// SSR-hydration stable) across any number of cards on one page.
const gradientId = `kx-spark-${useId()}`;

const paths = computed<{ line: string; area: string }>(() => {
    const chart = props.data;

    if (!chart || chart.length < 2) {
        return { line: '', area: '' };
    }

    const min = Math.min(...chart);
    const max = Math.max(...chart);
    const range = max - min === 0 ? 1 : max - min;
    const padding = 4;
    const usableHeight = props.height - padding * 2;

    const points = chart.map((val, index) => {
        const x = (index / (chart.length - 1)) * props.width;
        const y = props.height - padding - ((val - min) / range) * usableHeight;

        return { x, y };
    });

    const line = points
        .map(
            (p, i) =>
                `${i === 0 ? 'M' : 'L'} ${p.x.toFixed(1)} ${p.y.toFixed(1)}`,
        )
        .join(' ');

    const last = points[points.length - 1];
    const first = points[0];
    const area = `${line} L ${last.x.toFixed(1)} ${props.height} L ${first.x.toFixed(1)} ${props.height} Z`;

    return { line, area };
});
</script>

<template>
    <svg
        class="h-full w-full overflow-visible"
        :viewBox="`0 0 ${width} ${height}`"
        aria-hidden="true"
    >
        <defs>
            <linearGradient :id="gradientId" x1="0" y1="0" x2="0" y2="1">
                <stop
                    offset="0%"
                    stop-color="currentColor"
                    stop-opacity="0.2"
                />
                <stop
                    offset="100%"
                    stop-color="currentColor"
                    stop-opacity="0"
                />
            </linearGradient>
        </defs>
        <path :d="paths.area" :fill="`url(#${gradientId})`" stroke="none" />
        <path
            class="kinetix-sparkline-line"
            :d="paths.line"
            pathLength="1"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
        />
    </svg>
</template>

<style scoped>
/* Draw-in on mount (pathLength=1 normalizes the dash to the path's length). */
.kinetix-sparkline-line {
    stroke-dasharray: 1;
    stroke-dashoffset: 0;
    animation: kinetix-sparkline-draw 0.9s ease-out;
}
@keyframes kinetix-sparkline-draw {
    from {
        stroke-dashoffset: 1;
    }
    to {
        stroke-dashoffset: 0;
    }
}
@media (prefers-reduced-motion: reduce) {
    .kinetix-sparkline-line {
        animation: none;
    }
}
</style>
