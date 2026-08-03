<script setup lang="ts">
import { Donut } from '@unovis/ts';
import {
    VisArea,
    VisAxis,
    VisCrosshair,
    VisDonut,
    VisGroupedBar,
    VisLine,
    VisSingleContainer,
    VisStackedBar,
    VisTooltip,
    VisXYContainer,
} from '@unovis/vue';
import { computed } from 'vue';
import { useKinetixChartSurfaceVars } from '@/composables/useKinetixChartPalette';
import type { KinetixChartPoint, KinetixChartSlice } from '@/types/kinetix';

/**
 * The `@unovis`-backed chart surface (pie/donut + line/area/bar). Split out of
 * `KinetixChartWidget` and loaded via `defineAsyncComponent`, so the ~D3-sized
 * `@unovis` bundle is code-split and only fetched when a chart actually renders
 * — pages with widgets but no chart never pay for it. Horizontal-bar and legend
 * rendering stay in the parent (plain CSS, no `@unovis`).
 *
 * Tooltip wiring quirk: unovis' `Tooltip` component has NO `template` prop —
 * XY charts get their tooltip through `Crosshair`'s template, and the donut
 * through a `triggers` map keyed by the segment selector.
 */
/** A value read off a series point — numeric once the scale coerces it. */
type ChartValue = number | string | null | undefined;
type PointAccessor = (d: KinetixChartPoint | null) => ChartValue;

const props = defineProps<{
    chartType: string;
    isCircular: boolean;
    chartData: KinetixChartPoint[];
    pieData: KinetixChartSlice[];
    labels: string[];
    stacked: boolean;
    /** Animation duration; 0 under prefers-reduced-motion, undefined = library default. */
    duration: number | undefined;
    xAccessor: (d: KinetixChartPoint | null) => number | undefined;
    /** Raw per-series accessors (visible series only). */
    yAccessors: PointAccessor[];
    /**
     * Accessors matching the series' VISUAL height — cumulative for stacked
     * area/bar. Used for line overlays and crosshair circles.
     */
    visualAccessors: PointAccessor[];
    /** Concrete series colors aligned with yAccessors. */
    lineColors: string[];
    /** Area fills (gradient urls) aligned with yAccessors. */
    areaColors: string[];
    groupedBarColors: string[];
    pieValueAccessor: (d: KinetixChartSlice | null) => ChartValue;
    pieLabelAccessor: (d: KinetixChartSlice | null) => string | undefined;
    pieColorAccessor: (d: KinetixChartSlice | null, index: number) => string;
    arcWidthValue: number;
    centerValue: string | null;
    centerCaption: string | null;
    tooltipTemplate: (d: KinetixChartPoint | null) => string;
    pieTooltipTemplate: (d: KinetixChartSlice | null) => string;
}>();

/**
 * Donut tooltips fire per-segment; unovis hands the arc datum, whose `.data`
 * is the original slice.
 */
const donutTriggers = computed(() => ({
    [Donut.selectors.segment]: (arc: { data?: KinetixChartSlice } | null) =>
        props.pieTooltipTemplate(
            arc?.data ?? (arc as KinetixChartSlice | null),
        ),
}));

const crosshairColor = (_d: KinetixChartPoint | null, index: number): string =>
    props.lineColors[index] ?? 'currentColor';

// The unovis `--vis-*` color properties, resolved from theme tokens in JS —
// a CSS `hsl(var(--border))` wrapping breaks (silently) on hosts whose tokens
// are complete colors, e.g. the shadcn starter kit. See the composable.
const surfaceVars = useKinetixChartSurfaceVars();
</script>

<template>
    <!-- Circular Charts (Pie/Donut) -->
    <div
        v-if="isCircular"
        class="kinetix-chart-canvas relative h-[300px] w-full"
        :style="surfaceVars"
    >
        <VisSingleContainer :data="pieData" height="300" :duration="duration">
            <VisDonut
                :value="pieValueAccessor"
                :id="pieLabelAccessor"
                :arcWidth="arcWidthValue"
                :color="pieColorAccessor"
                :padAngle="0.02"
                :cornerRadius="4"
                :duration="duration"
            />
            <VisTooltip :triggers="donutTriggers" />
        </VisSingleContainer>
        <div
            v-if="centerValue"
            class="inset-0 pointer-events-none absolute flex flex-col items-center justify-center"
        >
            <span class="text-2xl font-bold text-foreground">{{
                centerValue
            }}</span>
            <span v-if="centerCaption" class="text-xs text-muted-foreground">{{
                centerCaption
            }}</span>
        </div>
    </div>

    <!-- XY Charts (Line/Area/Bar) -->
    <VisXYContainer
        v-else
        class="kinetix-chart-canvas"
        :style="surfaceVars"
        :data="chartData"
        height="300"
        :duration="duration"
    >
        <!-- Stacked area: one Area with the accessor array (unovis stacks it). -->
        <VisArea
            v-if="chartType === 'area' && stacked"
            :x="xAccessor"
            :y="yAccessors"
            :color="areaColors"
            :duration="duration"
        />
        <!-- Overlaid area: one translucent Area per series, at raw values. -->
        <template v-else-if="chartType === 'area'">
            <VisArea
                v-for="(_, index) in yAccessors"
                :key="`area-${index}`"
                :x="xAccessor"
                :y="yAccessors[index]"
                :color="areaColors[index]"
                :duration="duration"
            />
        </template>
        <template v-if="chartType === 'line' || chartType === 'area'">
            <!-- Line overlays sit at the series' VISUAL height (cumulative when stacked). -->
            <VisLine
                v-for="(_, index) in visualAccessors"
                :key="`line-${index}`"
                :x="xAccessor"
                :y="visualAccessors[index]"
                :color="lineColors[index]"
                :duration="duration"
            />
        </template>
        <VisStackedBar
            v-if="chartType === 'bar' && stacked"
            :x="xAccessor"
            :y="yAccessors"
            :color="groupedBarColors"
            :roundedCorners="4"
            :barPadding="0.2"
            :duration="duration"
        />
        <VisGroupedBar
            v-else-if="chartType === 'bar'"
            :x="xAccessor"
            :y="yAccessors"
            :color="groupedBarColors"
            :roundedCorners="4"
            :barPadding="0.2"
            :duration="duration"
        />
        <VisAxis
            type="x"
            :tickValues="chartData.map((d) => d.x)"
            :tickFormat="(tickVal: number) => labels[tickVal] || ''"
            :duration="duration"
        />
        <VisAxis type="y" :duration="duration" />
        <VisCrosshair
            :template="tooltipTemplate"
            :x="xAccessor"
            :y="visualAccessors"
            :color="crosshairColor"
        />
        <VisTooltip />
    </VisXYContainer>
</template>

<style scoped>
/*
 * unovis surfaces are themed exclusively through its own CSS custom properties
 * (its class names are emotion-generated, so element selectors don't reach
 * them). Only LITERAL values live here — every token-derived color is bound
 * from JS as an inline style (`useKinetixChartSurfaceVars`), because a CSS
 * `hsl(var(--border))` wrapping is invalid on hosts whose tokens are complete
 * colors (the shadcn starter-kit convention) and gets dropped silently. The
 * tooltip container is neutralized (our templates render a complete card).
 */
.kinetix-chart-canvas {
    --vis-tooltip-background-color: transparent;
    --vis-tooltip-border-color: transparent;
    --vis-tooltip-padding: 0;
    --vis-tooltip-box-shadow: none;
    --vis-crosshair-line-stroke-opacity: 0.4;
    --vis-crosshair-circle-stroke-width: 2px;
    --vis-axis-tick-label-font-size: 11px;
    --vis-axis-font-family: inherit;
}
</style>
