<script setup lang="ts">
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

/**
 * The `@unovis`-backed chart surface (pie/donut + line/area/bar). Split out of
 * `KinetixChartWidget` and loaded via `defineAsyncComponent`, so the ~D3-sized
 * `@unovis` bundle is code-split and only fetched when a chart actually renders
 * — pages with widgets but no chart never pay for it. Horizontal-bar and legend
 * rendering stay in the parent (plain CSS, no `@unovis`).
 */
defineProps<{
    chartType: string;
    isCircular: boolean;
    chartData: any[];
    pieData: any[];
    datasets: any[];
    labels: string[];
    stacked: boolean;
    xAccessor: (d: any) => any;
    yAccessors: ((d: any) => any)[];
    colorAccessor: (d: any, index: number) => string;
    areaColors: string[];
    groupedBarColors: string[];
    pieValueAccessor: (d: any) => any;
    pieLabelAccessor: (d: any) => any;
    pieColorAccessor: (d: any, index: number) => string;
    arcWidthValue: number;
    centerValue: string | null;
    centerCaption: string | null;
    tooltipTemplate: (d: any) => string;
    pieTooltipTemplate: (d: any) => string;
}>();
</script>

<template>
    <!-- Circular Charts (Pie/Donut) -->
    <div v-if="isCircular" class="relative h-[300px] w-full">
        <VisSingleContainer :data="pieData" height="300">
            <VisDonut
                :value="pieValueAccessor"
                :id="pieLabelAccessor"
                :arcWidth="arcWidthValue"
                :color="pieColorAccessor"
            />
            <VisTooltip :template="pieTooltipTemplate" />
        </VisSingleContainer>
        <div
            v-if="centerValue"
            class="inset-0 pointer-events-none absolute flex flex-col items-center justify-center"
        >
            <span class="text-2xl font-bold text-foreground">{{
                centerValue
            }}</span>
            <span
                v-if="centerCaption"
                class="text-xs text-muted-foreground"
                >{{ centerCaption }}</span
            >
        </div>
    </div>

    <!-- XY Charts (Line/Area/Bar) -->
    <VisXYContainer v-else :data="chartData" height="300">
        <template v-if="chartType === 'line' || chartType === 'area'">
            <VisArea
                v-if="chartType === 'area'"
                :x="xAccessor"
                :y="yAccessors"
                :color="areaColors"
            />
            <VisLine
                v-for="(_, index) in datasets"
                :key="index"
                :x="xAccessor"
                :y="yAccessors[index]"
                :color="colorAccessor(null, index)"
            />
        </template>
        <VisStackedBar
            v-if="chartType === 'bar' && stacked"
            :x="xAccessor"
            :y="yAccessors"
            :color="groupedBarColors"
        />
        <VisGroupedBar
            v-else-if="chartType === 'bar'"
            :x="xAccessor"
            :y="yAccessors"
            :color="groupedBarColors"
        />
        <VisAxis
            type="x"
            :tickValues="chartData.map((d) => d.x)"
            :tickFormat="(tickVal: number) => labels[tickVal] || ''"
        />
        <VisAxis type="y" />
        <VisCrosshair />
        <VisTooltip :template="tooltipTemplate" />
    </VisXYContainer>
</template>

<style scoped>
:deep(.vis-axis-grid) {
    stroke: #e2e8f0;
    stroke-opacity: 0.15;
}
.dark :deep(.vis-axis-grid) {
    stroke: #1e293b;
    stroke-opacity: 0.2;
}
:deep(.vis-axis-tick),
:deep(.vis-axis-line) {
    stroke: #cbd5e1;
    stroke-opacity: 0.3;
}
.dark :deep(.vis-axis-tick),
.dark :deep(.vis-axis-line) {
    stroke: #334155;
    stroke-opacity: 0.4;
}
:deep(.vis-axis-tick-label) {
    fill: #64748b;
    font-size: 10px;
    font-family: inherit;
}
.dark :deep(.vis-axis-tick-label) {
    fill: #94a3b8;
}
</style>
