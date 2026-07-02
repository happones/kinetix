<script setup lang="ts">
import {
    VisXYContainer,
    VisSingleContainer,
    VisArea,
    VisLine,
    VisGroupedBar,
    VisStackedBar,
    VisDonut,
    VisAxis,
    VisTooltip,
    VisCrosshair,
} from '@unovis/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { statusBadgeClass } from '@/composables/useStatusColor';
import type { KinetixChartMetric, KinetixWidget } from '@/types';
import Card from './primitives/Card.vue';
import CardContent from './primitives/CardContent.vue';
import CardDescription from './primitives/CardDescription.vue';
import CardHeader from './primitives/CardHeader.vue';
import CardTitle from './primitives/CardTitle.vue';
import KinetixEmptyState from './KinetixEmptyState.vue';
import WidgetHeaderActions from './widgets/WidgetHeaderActions.vue';

const { t } = useI18n();

const props = defineProps<{
    widget: KinetixWidget;
}>();

const labels = computed<string[]>(() => props.widget.data.labels || []);
const datasets = computed<any[]>(() => props.widget.data.datasets || []);
const chartType = computed<string>(() => props.widget.data.chartType || 'line');
const stacked = computed<boolean>(() => !!props.widget.data.stacked);
const showLegend = computed<boolean>(() => !!props.widget.data.legend);
const centerValue = computed<string | null>(
    () => props.widget.data.centerValue ?? null,
);
const centerCaption = computed<string | null>(
    () => props.widget.data.centerLabel ?? null,
);
const isHorizontalBar = computed(() => chartType.value === 'horizontalBar');
const metrics = computed<KinetixChartMetric[]>(
    () => props.widget.data.metrics ?? [],
);

// Transform standard chart dataset structure to Unovis format
// Map string labels to numeric indices to avoid NaN errors on continuous scale
const chartData = computed(() => {
    const lbls = labels.value;
    const dts = datasets.value;

    return lbls.map((label: string, index: number) => {
        const point: Record<string, any> = {
            x: index,
            label: label,
        };
        dts.forEach((dataset: any, dIndex: number) => {
            point[`y_${dIndex}`] = dataset.data[index] ?? 0;
        });

        return point;
    });
});

const hasData = computed(() => {
    if (isCircular.value) {
        return pieData.value.length > 0;
    }
    if (isHorizontalBar.value) {
        return horizontalBars.value.length > 0;
    }
    return chartData.value.length > 0;
});

const xAccessor = (d: any) => d?.x;

const yAccessors = computed(() => {
    return datasets.value.map(
        (_: any, index: number) => (d: any) => d?.[`y_${index}`],
    );
});

// Vibrant modern colors
const themeColors = [
    '#3b82f6', // blue
    '#10b981', // emerald
    '#f59e0b', // amber
    '#8b5cf6', // violet
    '#ec4899', // pink
    '#f43f5e', // rose
    '#0ea5e9', // sky
];

const colorAccessor = (_: any, index: number) => {
    const customColor =
        datasets.value[index]?.borderColor ||
        datasets.value[index]?.backgroundColor;

    if (customColor && typeof customColor === 'string') {
        return customColor;
    }

    return themeColors[index % themeColors.length];
};

const groupedBarColors = computed(() => {
    return datasets.value.map((_, index) => colorAccessor(null, index));
});

// Area fills use a vertical gradient (solid → transparent), shadcn-style. Each
// series gets a unique gradient def referenced by `fill: url(#id)`.
const gradientUid = computed(() =>
    String(props.widget.id ?? 'chart').replace(/[^a-zA-Z0-9_-]/g, ''),
);
const areaGradientId = (index: number): string =>
    `kx-area-${gradientUid.value}-${index}`;
const areaColors = computed(() =>
    datasets.value.map((_, index) => `url(#${areaGradientId(index)})`),
);

// Legend entries — dataset labels for XY charts, category labels for donut/horizontal.
const legendItems = computed<{ label: string; color: string }[]>(() => {
    if (isCircular.value || isHorizontalBar.value) {
        return labels.value.map((label, index) => ({
            label,
            color: themeColors[index % themeColors.length],
        }));
    }

    return datasets.value.map((dataset, index) => ({
        label: dataset.label ?? `Series ${index + 1}`,
        color: colorAccessor(null, index),
    }));
});

// Div-based horizontal bars (reliable, crisp) from the first dataset.
const horizontalBars = computed<
    { label: string; value: number; pct: number; color: string }[]
>(() => {
    const data: number[] = datasets.value[0]?.data ?? [];
    const max = Math.max(1, ...data.map((v) => Number(v) || 0));

    return labels.value.map((label, index) => {
        const value = Number(data[index]) || 0;

        return {
            label,
            value,
            pct: Math.round((value / max) * 100),
            color: themeColors[index % themeColors.length],
        };
    });
});

const isCircular = computed(() => {
    const type = chartType.value;

    if (type === 'pie') {
        return true;
    }

    if (type === 'doughnut') {
        return true;
    }

    return false;
});

const pieData = computed(() => {
    if (!isCircular.value) {
        return [];
    }

    const lbls = labels.value;
    const dataset = datasets.value[0];

    if (!dataset || !Array.isArray(dataset.data)) {
        return [];
    }

    return lbls.map((label: string, index: number) => ({
        label,
        value: dataset.data[index] ?? 0,
    }));
});

const pieValueAccessor = (d: any) => d?.value;
const pieLabelAccessor = (d: any) => d?.label;
const pieColorAccessor = (_: any, index: number) =>
    themeColors[index % themeColors.length];

const arcWidthValue = computed(() => {
    if (chartType.value === 'pie') {
        return 0; // full pie
    }

    return 40; // donut
});

const tooltipTemplate = (d: any) => {
    if (!d) {
        return '';
    }

    const label = d.label || '';
    let html = `<div class="p-3 text-xs font-sans bg-popover/95 text-popover-foreground rounded-lg border border-border shadow-md">
        <div class="font-bold mb-2 border-b border-border pb-1.5">${label}</div>`;

    datasets.value.forEach((dataset: any, index: number) => {
        const val = d[`y_${index}`];
        const color = colorAccessor(null, index);
        html += `<div class="flex items-center gap-4 mt-1.5 min-w-[120px]">
            <span class="w-2.5 h-2.5 rounded-full shrink-0" style="background-color: ${color}"></span>
            <span class="text-muted-foreground font-medium">${dataset.label || 'Value'}:</span>
            <span class="text-popover-foreground font-bold ml-auto">${val}</span>
        </div>`;
    });

    html += `</div>`;

    return html;
};

const pieTooltipTemplate = (d: any) => {
    if (!d) {
        return '';
    }

    const color = pieColorAccessor(null, pieData.value.indexOf(d));

    return `<div class="p-3 text-xs font-sans bg-popover/95 text-popover-foreground rounded-lg border border-border shadow-md flex items-center gap-4 min-w-[120px]">
        <span class="w-2.5 h-2.5 rounded-full shrink-0" style="background-color: ${color}"></span>
        <span class="text-muted-foreground font-medium">${d.label}:</span>
        <span class="text-popover-foreground font-bold ml-auto">${d.value}</span>
    </div>`;
};
</script>

<template>
    <Card
        class="kinetix-chart-card hover:shadow-md transition-all duration-300"
    >
        <CardHeader
            v-if="
                widget.title ||
                widget.description ||
                widget.headerActions?.length ||
                metrics.length
            "
        >
            <div class="gap-3 flex items-start justify-between">
                <div class="min-w-0">
                    <CardTitle v-if="widget.title" class="text-base">{{
                        widget.title
                    }}</CardTitle>
                    <CardDescription v-if="widget.description" class="text-xs">
                        {{ widget.description }}
                    </CardDescription>
                </div>
                <div class="gap-4 flex shrink-0 items-center">
                    <!-- Header metrics (e.g. DESKTOP / MOBILE totals) -->
                    <div
                        v-for="(metric, i) in metrics"
                        :key="i"
                        class="text-right"
                    >
                        <div
                            class="font-medium tracking-wide text-[10px] text-muted-foreground uppercase"
                        >
                            {{ metric.label }}
                        </div>
                        <div
                            class="gap-1.5 text-lg font-bold flex items-center justify-end text-foreground"
                        >
                            {{ metric.value }}
                            <span
                                v-if="metric.badge"
                                class="px-1.5 py-0.5 text-xs font-medium rounded-full"
                                :class="
                                    statusBadgeClass(metric.badgeColor as any)
                                "
                                >{{ metric.badge }}</span
                            >
                        </div>
                    </div>
                    <WidgetHeaderActions :actions="widget.headerActions" />
                </div>
            </div>
        </CardHeader>

        <CardContent class="font-sans w-full text-muted-foreground">
            <!-- Area gradient defs (referenced by VisArea fill) -->
            <svg
                v-if="chartType === 'area' && hasData"
                aria-hidden="true"
                style="position: absolute; width: 0; height: 0"
            >
                <defs>
                    <linearGradient
                        v-for="(_, index) in datasets"
                        :id="areaGradientId(index)"
                        :key="index"
                        x1="0"
                        y1="0"
                        x2="0"
                        y2="1"
                    >
                        <stop
                            offset="0%"
                            :stop-color="colorAccessor(null, index)"
                            stop-opacity="0.4"
                        />
                        <stop
                            offset="100%"
                            :stop-color="colorAccessor(null, index)"
                            stop-opacity="0"
                        />
                    </linearGradient>
                </defs>
            </svg>

            <template v-if="hasData">
                <!-- Horizontal bars (div-based, crisp) -->
                <div v-if="isHorizontalBar" class="space-y-3 py-2">
                    <div
                        v-for="bar in horizontalBars"
                        :key="bar.label"
                        class="gap-3 flex items-center"
                    >
                        <span
                            class="text-xs w-24 shrink-0 truncate text-right text-muted-foreground"
                            >{{ bar.label }}</span
                        >
                        <span
                            class="h-6 flex-1 overflow-hidden rounded-md bg-muted/40"
                        >
                            <span
                                class="block h-full rounded-md transition-all"
                                :style="{
                                    width: `${bar.pct}%`,
                                    backgroundColor: bar.color,
                                }"
                            />
                        </span>
                        <span
                            class="text-xs font-medium w-12 shrink-0 text-foreground tabular-nums"
                            >{{ bar.value }}</span
                        >
                    </div>
                </div>

                <!-- Circular Charts (Pie/Donut) -->
                <div v-else-if="isCircular" class="relative h-[300px] w-full">
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
                    <template
                        v-if="chartType === 'line' || chartType === 'area'"
                    >
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
            <template v-else>
                <KinetixEmptyState
                    :title="t('kinetix.chart_empty')"
                    icon="trending-up"
                />
            </template>

            <!-- Legend -->
            <div
                v-if="showLegend && legendItems.length && hasData"
                class="mt-4 gap-4 flex flex-wrap items-center justify-center"
            >
                <span
                    v-for="item in legendItems"
                    :key="item.label"
                    class="gap-1.5 text-xs flex items-center text-muted-foreground"
                >
                    <span
                        class="size-2.5 rounded-full"
                        :style="{ backgroundColor: item.color }"
                    />
                    {{ item.label }}
                </span>
            </div>
        </CardContent>
    </Card>
</template>

<style scoped>
.kinetix-chart-card {
    width: 100%;
}

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
