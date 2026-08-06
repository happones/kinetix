<script setup lang="ts">
import { computed, defineAsyncComponent, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixChartPalette } from '@/composables/useKinetixChartPalette';
import type {
    KinetixChartData,
    KinetixChartDataset,
    KinetixChartMetric,
    KinetixChartPoint,
    KinetixChartSlice,
    KinetixWidget,
} from '@/types/kinetix';
import KinetixEmptyState from './KinetixEmptyState.vue';
import Card from './primitives/Card.vue';
import CardContent from './primitives/CardContent.vue';
import CardDescription from './primitives/CardDescription.vue';
import CardHeader from './primitives/CardHeader.vue';
import CardTitle from './primitives/CardTitle.vue';
import KinetixBadge from './primitives/KinetixBadge.vue';
import WidgetHeaderActions from './widgets/WidgetHeaderActions.vue';

// The `@unovis` chart surface is code-split: only fetched when a chart with data
// actually renders, so widget pages without charts never ship the D3-sized dep.
const UnovisChartCanvas = defineAsyncComponent(
    () => import('./widgets/UnovisChartCanvas.vue'),
);

const { t } = useI18n();

const props = defineProps<{
    widget: KinetixWidget;
}>();

// `KinetixWidget.data` is the untyped union of every widget kind's payload; a
// chart widget's slice of it is `KinetixChartData`.
const data = computed<KinetixChartData>(
    () => props.widget.data as KinetixChartData,
);

const labels = computed<string[]>(() => data.value.labels || []);
const datasets = computed<KinetixChartDataset[]>(
    () => data.value.datasets || [],
);
const chartType = computed<string>(() => data.value.chartType || 'line');
const stacked = computed<boolean>(() => !!data.value.stacked);
const centerValue = computed<string | null>(
    () => data.value.centerValue ?? null,
);
const centerCaption = computed<string | null>(
    () => data.value.centerLabel ?? null,
);
const isHorizontalBar = computed(() => chartType.value === 'horizontalBar');
const metrics = computed<KinetixChartMetric[]>(() => data.value.metrics ?? []);

const isCircular = computed(() => {
    const type = chartType.value;

    return type === 'pie' || type === 'doughnut';
});

// Categorical series colors resolve from the theme's --chart-N tokens, so the
// palette follows light/dark mode (and any host re-skin) automatically.
const seriesPalette = useKinetixChartPalette();

// Respect prefers-reduced-motion: charts render their final state immediately.
const prefersReducedMotion =
    typeof window !== 'undefined' &&
    !!window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
const chartDuration = computed<number | undefined>(() =>
    prefersReducedMotion ? 0 : undefined,
);

/**
 * The color of series `index`: an explicit dataset color wins; otherwise the
 * theme palette, keyed by the series' ORIGINAL index so hiding other series
 * never repaints the survivors.
 */
const seriesColor = (index: number): string => {
    const dataset = datasets.value[index];
    const customColor = dataset?.borderColor || dataset?.backgroundColor;

    if (customColor && typeof customColor === 'string') {
        return customColor;
    }

    return seriesPalette.value[index % seriesPalette.value.length];
};

/**
 * The color of category `index` in a pie/horizontal-bar chart: a per-slice
 * `backgroundColor` array entry wins, then a scalar custom color, then the
 * theme palette.
 */
const sliceColor = (index: number): string => {
    const background = datasets.value[0]?.backgroundColor;

    if (Array.isArray(background) && typeof background[index] === 'string') {
        return background[index];
    }

    if (background && typeof background === 'string') {
        return background;
    }

    return seriesPalette.value[index % seriesPalette.value.length];
};

// --- Legend (auto by default, click-to-toggle) -------------------------------

/**
 * Hidden entries — dataset indices for XY charts, category indices for
 * circular/horizontal ones. The last visible entry can't be hidden, so the
 * chart never renders empty.
 */
const hiddenEntries = ref<Set<number>>(new Set());

const legendItems = computed<
    { label: string; color: string; index: number; hidden: boolean }[]
>(() => {
    if (isCircular.value || isHorizontalBar.value) {
        return labels.value.map((label, index) => ({
            label,
            color: sliceColor(index),
            index,
            hidden: hiddenEntries.value.has(index),
        }));
    }

    return datasets.value.map((dataset, index) => ({
        label: dataset.label ?? `Series ${index + 1}`,
        color: seriesColor(index),
        index,
        hidden: hiddenEntries.value.has(index),
    }));
});

// Legend defaults to on whenever identity needs disambiguating (≥ 2 entries);
// an explicit legend flag from the server always wins.
const showLegend = computed<boolean>(() => {
    if (typeof data.value.legend === 'boolean') {
        return data.value.legend;
    }

    return legendItems.value.length > 1;
});

const toggleLegendEntry = (index: number): void => {
    const hidden = new Set(hiddenEntries.value);

    if (hidden.has(index)) {
        hidden.delete(index);
    } else {
        if (hidden.size >= legendItems.value.length - 1) {
            return;
        }

        hidden.add(index);
    }

    hiddenEntries.value = hidden;
};

const visibleDatasetIndices = computed<number[]>(() =>
    datasets.value
        .map((_, index) => index)
        .filter((index) => !hiddenEntries.value.has(index)),
);

const visibleLabelIndices = computed<number[]>(() =>
    labels.value
        .map((_, index) => index)
        .filter((index) => !hiddenEntries.value.has(index)),
);

// --- XY chart data -----------------------------------------------------------

// Transform standard chart dataset structure to Unovis format
// Map string labels to numeric indices to avoid NaN errors on continuous scale
const chartData = computed<KinetixChartPoint[]>(() => {
    const dts = datasets.value;

    return labels.value.map((label, index) => {
        const point: KinetixChartPoint = { x: index, label };

        dts.forEach((dataset, dIndex) => {
            point[`y_${dIndex}`] = dataset.data[index] ?? 0;
        });

        return point;
    });
});

const xAccessor = (d: KinetixChartPoint | null): number | undefined => d?.x;

type PointAccessor = (
    d: KinetixChartPoint | null,
) => number | string | null | undefined;

const yAccessors = computed<PointAccessor[]>(() =>
    visibleDatasetIndices.value.map((index) => (d) => d?.[`y_${index}`]),
);

// Whether series stack visually (stacked bars, or any multi-series area chart
// marked stacked) — the crosshair/line overlays must sit at cumulative heights.
const seriesAreStacked = computed(
    () =>
        stacked.value &&
        (chartType.value === 'bar' || chartType.value === 'area'),
);

const visualAccessors = computed<PointAccessor[]>(() => {
    if (!seriesAreStacked.value) {
        return yAccessors.value;
    }

    return visibleDatasetIndices.value.map((_, position) => (d) => {
        let sum = 0;

        for (const index of visibleDatasetIndices.value.slice(
            0,
            position + 1,
        )) {
            sum += Number(d?.[`y_${index}`]) || 0;
        }

        return sum;
    });
});

const lineColors = computed<string[]>(() =>
    visibleDatasetIndices.value.map((index) => seriesColor(index)),
);

const groupedBarColors = computed<string[]>(() => lineColors.value);

// Area fills use a vertical gradient (solid → transparent), shadcn-style. Each
// series gets a unique gradient def referenced by `fill: url(#id)`, keyed by
// the ORIGINAL dataset index so visibility toggles keep colors stable.
const gradientUid = computed(() =>
    String(props.widget.id ?? 'chart').replace(/[^a-zA-Z0-9_-]/g, ''),
);
const areaGradientId = (index: number): string =>
    `kx-area-${gradientUid.value}-${index}`;
const areaColors = computed<string[]>(() =>
    visibleDatasetIndices.value.map(
        (index) => `url(#${areaGradientId(index)})`,
    ),
);

// --- Pie/donut data ----------------------------------------------------------

const pieData = computed<KinetixChartSlice[]>(() => {
    if (!isCircular.value) {
        return [];
    }

    const dataset = datasets.value[0];

    if (!dataset || !Array.isArray(dataset.data)) {
        return [];
    }

    return visibleLabelIndices.value.map((index) => ({
        label: labels.value[index],
        value: dataset.data[index] ?? 0,
    }));
});

const pieSliceColors = computed<string[]>(() =>
    visibleLabelIndices.value.map((index) => sliceColor(index)),
);

const pieValueAccessor = (
    d: KinetixChartSlice | null,
): number | string | null | undefined => d?.value;
const pieLabelAccessor = (d: KinetixChartSlice | null): string | undefined =>
    d?.label;
const pieColorAccessor = (
    _d: KinetixChartSlice | null,
    index: number,
): string => pieSliceColors.value[index];

const arcWidthValue = computed(() => {
    if (chartType.value === 'pie') {
        return 0; // full pie
    }

    return 40; // donut
});

// --- Horizontal bars ---------------------------------------------------------

// Div-based horizontal bars (reliable, crisp) from the first dataset.
const horizontalBars = computed<
    { label: string; value: number; pct: number; color: string }[]
>(() => {
    const values = datasets.value[0]?.data ?? [];
    const max = Math.max(
        1,
        ...visibleLabelIndices.value.map((i) => Number(values[i]) || 0),
    );

    return visibleLabelIndices.value.map((index) => {
        const value = Number(values[index]) || 0;

        return {
            label: labels.value[index],
            value,
            pct: Math.round((value / max) * 100),
            color: sliceColor(index),
        };
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

// --- Tooltips ------------------------------------------------------------------

const tooltipTemplate = (d: KinetixChartPoint | null): string => {
    if (!d) {
        return '';
    }

    const label = d.label || '';
    let html = `<div class="p-3 text-xs font-sans bg-popover/95 backdrop-blur-sm text-popover-foreground rounded-lg border border-border shadow-md">
        <div class="font-bold mb-2 border-b border-border pb-1.5">${label}</div>`;

    visibleDatasetIndices.value.forEach((index) => {
        const dataset = datasets.value[index];
        const val = d[`y_${index}`];
        const color = seriesColor(index);
        html += `<div class="flex items-center gap-4 mt-1.5 min-w-[120px]">
            <span class="w-2.5 h-2.5 rounded-full shrink-0" style="background-color: ${color}"></span>
            <span class="text-muted-foreground font-medium">${dataset?.label || 'Value'}:</span>
            <span class="text-popover-foreground font-bold ml-auto tabular-nums">${val}</span>
        </div>`;
    });

    html += `</div>`;

    return html;
};

const pieTooltipTemplate = (d: KinetixChartSlice | null): string => {
    if (!d) {
        return '';
    }

    const index = pieData.value.indexOf(d);
    const color = pieSliceColors.value[index] ?? seriesPalette.value[0];
    const total = pieData.value.reduce(
        (sum, slice) => sum + (Number(slice.value) || 0),
        0,
    );
    const share = total > 0 ? ((Number(d.value) || 0) / total) * 100 : 0;

    return `<div class="p-3 text-xs font-sans bg-popover/95 backdrop-blur-sm text-popover-foreground rounded-lg border border-border shadow-md flex items-center gap-4 min-w-[140px]">
        <span class="w-2.5 h-2.5 rounded-full shrink-0" style="background-color: ${color}"></span>
        <span class="text-muted-foreground font-medium">${d.label}:</span>
        <span class="text-popover-foreground font-bold ml-auto tabular-nums">${d.value}</span>
        <span class="text-muted-foreground tabular-nums">${share.toFixed(1)}%</span>
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
                            class="gap-1.5 text-lg font-bold flex items-center justify-end text-foreground tabular-nums"
                        >
                            {{ metric.value }}
                            <KinetixBadge
                                v-if="metric.badge"
                                :color="metric.badgeColor"
                                size="sm"
                                >{{ metric.badge }}</KinetixBadge
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
                            :stop-color="seriesColor(index)"
                            stop-opacity="0.4"
                        />
                        <stop
                            offset="100%"
                            :stop-color="seriesColor(index)"
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
                                class="block h-full rounded-md transition-all duration-300 motion-reduce:transition-none"
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

                <!-- Pie/Donut + Line/Area/Bar via the code-split @unovis canvas. -->
                <UnovisChartCanvas
                    v-else
                    :chart-type="chartType"
                    :is-circular="isCircular"
                    :chart-data="chartData"
                    :pie-data="pieData"
                    :labels="labels"
                    :stacked="stacked"
                    :duration="chartDuration"
                    :x-accessor="xAccessor"
                    :y-accessors="yAccessors"
                    :visual-accessors="visualAccessors"
                    :line-colors="lineColors"
                    :area-colors="areaColors"
                    :grouped-bar-colors="groupedBarColors"
                    :pie-value-accessor="pieValueAccessor"
                    :pie-label-accessor="pieLabelAccessor"
                    :pie-color-accessor="pieColorAccessor"
                    :arc-width-value="arcWidthValue"
                    :center-value="centerValue"
                    :center-caption="centerCaption"
                    :tooltip-template="tooltipTemplate"
                    :pie-tooltip-template="pieTooltipTemplate"
                />
            </template>
            <template v-else>
                <KinetixEmptyState
                    :title="t('kinetix.chart_empty')"
                    icon="trending-up"
                />
            </template>

            <!-- Legend (click an entry to toggle its series) -->
            <div
                v-if="showLegend && legendItems.length && hasData"
                class="mt-4 gap-x-1 gap-y-1 flex flex-wrap items-center justify-center"
            >
                <button
                    v-for="item in legendItems"
                    :key="item.index"
                    type="button"
                    class="gap-1.5 text-xs px-2 py-1 flex cursor-pointer items-center rounded-md text-muted-foreground transition-colors outline-none hover:bg-accent/60 hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    :class="item.hidden ? 'opacity-45' : ''"
                    :aria-pressed="!item.hidden"
                    @click="toggleLegendEntry(item.index)"
                >
                    <span
                        class="size-2.5 rounded-full transition-colors"
                        :style="{
                            backgroundColor: item.hidden
                                ? 'transparent'
                                : item.color,
                            boxShadow: item.hidden
                                ? `inset 0 0 0 1.5px ${item.color}`
                                : 'none',
                        }"
                    />
                    <span :class="item.hidden ? 'line-through' : ''">{{
                        item.label
                    }}</span>
                </button>
            </div>
        </CardContent>
    </Card>
</template>

<style scoped>
.kinetix-chart-card {
    width: 100%;
}
</style>
