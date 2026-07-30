<script setup lang="ts">
import { computed } from 'vue';
import type { Component } from 'vue';
import type { KinetixWidget, KinetixWidgetsGridData } from '@/types/kinetix';
import KinetixChartWidget from './KinetixChartWidget.vue';
import KinetixCustomWidget from './KinetixCustomWidget.vue';
import KinetixHealthStatus from './KinetixHealthStatus.vue';
import KinetixHeroWidget from './KinetixHeroWidget.vue';
import KinetixListWidget from './KinetixListWidget.vue';
import KinetixProgressWidget from './KinetixProgressWidget.vue';
import KinetixQueueStats from './KinetixQueueStats.vue';
import KinetixRatingWidget from './KinetixRatingWidget.vue';
import KinetixStatsOverviewWidget from './KinetixStatsOverviewWidget.vue';
import KinetixTableWidget from './KinetixTableWidget.vue';
import KinetixMasonryColumns from './widgets/KinetixMasonryColumns.vue';

const props = defineProps<{
    grid: KinetixWidgetsGridData;
}>();

// Every non-`custom` widget type dispatches through this map (shared by both
// the grid and masonry layouts); `custom` is special-cased in the template
// since it needs the parent's own per-widget-id named slot, which a generic
// `<component :is>` passthrough can't express.
const WIDGET_COMPONENTS: Partial<Record<KinetixWidget['type'], Component>> = {
    stats: KinetixStatsOverviewWidget,
    chart: KinetixChartWidget,
    table: KinetixTableWidget,
    list: KinetixListWidget,
    rating: KinetixRatingWidget,
    progress: KinetixProgressWidget,
    hero: KinetixHeroWidget,
    'queue-stats': KinetixQueueStats,
    'health-status': KinetixHealthStatus,
};

const isMasonry = computed(() => props.grid.layout === 'masonry');

const getGridStyle = (columns: any, gap: any, dense: boolean) => {
    const style: Record<string, string> = {};

    if (dense) {
        style['--grid-auto-flow'] = 'dense';
    }

    if (typeof gap === 'number') {
        style['--grid-gap-default'] = `${gap}px`;
    } else if (typeof gap === 'string') {
        style['--grid-gap-default'] = gap;
    } else if (typeof gap === 'object' && gap !== null) {
        const g = gap as Record<string, number | string>;
        const asLength = (v: number | string) =>
            typeof v === 'number' ? `${v}px` : v;
        style['--grid-gap-default'] = asLength(g.default ?? '1.5rem');

        for (const bp of ['sm', 'md', 'lg', 'xl', '2xl'] as const) {
            if (g[bp] !== undefined) {
                style[`--grid-gap-${bp}`] = asLength(g[bp]);
            }
        }
    }

    if (typeof columns === 'number' || typeof columns === 'string') {
        style['--grid-columns-default'] = `${columns}`;

        return style;
    }

    if (typeof columns === 'object' && columns !== null) {
        const cols = columns as Record<string, number | string>;
        style['--grid-columns-default'] = `${cols.default ?? 12}`;

        for (const bp of ['sm', 'md', 'lg', 'xl', '2xl'] as const) {
            if (cols[bp] !== undefined) {
                style[`--grid-columns-${bp}`] = `${cols[bp]}`;
            }
        }
    }

    return style;
};

const getItemStyle = (widget: KinetixWidget) => {
    const span = widget.columnSpan;
    const style: Record<string, string> = {};

    // Get max columns for full span fallback
    let maxCols = 12;

    if (typeof props.grid.columns === 'number') {
        maxCols = props.grid.columns;
    }

    if (typeof span === 'number' || typeof span === 'string') {
        if (span === 'full') {
            style['grid-column'] = '1 / -1';
        } else {
            style['--col-span-default'] = `${span}`;
        }

        return style;
    }

    if (typeof span === 'object' && span !== null) {
        const spanObj = span as Record<string, number | string>;
        const defVal = spanObj.default ?? 12;

        if (defVal === 'full') {
            style['grid-column'] = '1 / -1';
        } else {
            style['--col-span-default'] = `${defVal}`;
        }

        if (spanObj.sm !== undefined) {
            style['--col-span-sm'] =
                spanObj.sm === 'full' ? `${maxCols}` : `${spanObj.sm}`;
        }

        if (spanObj.md !== undefined) {
            style['--col-span-md'] =
                spanObj.md === 'full' ? `${maxCols}` : `${spanObj.md}`;
        }

        if (spanObj.lg !== undefined) {
            style['--col-span-lg'] =
                spanObj.lg === 'full' ? `${maxCols}` : `${spanObj.lg}`;
        }

        if (spanObj.xl !== undefined) {
            style['--col-span-xl'] =
                spanObj.xl === 'full' ? `${maxCols}` : `${spanObj.xl}`;
        }

        if (spanObj['2xl'] !== undefined) {
            style['--col-span-2xl'] =
                spanObj['2xl'] === 'full' ? `${maxCols}` : `${spanObj['2xl']}`;
        }
    }

    return style;
};
</script>

<template>
    <!-- ===== Masonry: column-balanced, no columnSpan ===== -->
    <KinetixMasonryColumns
        v-if="isMasonry"
        :widgets="grid.widgets"
        :columns="grid.masonryColumns"
        :gap="grid.gap"
    >
        <template #item="{ widget }">
            <KinetixCustomWidget
                v-if="widget.type === 'custom'"
                :widget="widget"
            >
                <slot :name="widget.id" :widget="widget" />
            </KinetixCustomWidget>
            <component
                :is="WIDGET_COMPONENTS[widget.type]"
                v-else-if="WIDGET_COMPONENTS[widget.type]"
                :widget="widget"
            />
        </template>
    </KinetixMasonryColumns>

    <!-- ===== Grid: columnSpan-based CSS grid, optionally dense-packed ===== -->
    <div
        v-else
        class="kinetix-widgets-grid w-full"
        :style="getGridStyle(grid.columns, grid.gap, grid.dense)"
    >
        <div
            v-for="widget in grid.widgets"
            :key="widget.id"
            class="kinetix-grid-item"
            :style="getItemStyle(widget)"
        >
            <KinetixCustomWidget
                v-if="widget.type === 'custom'"
                :widget="widget"
            >
                <slot :name="widget.id" :widget="widget" />
            </KinetixCustomWidget>
            <component
                :is="WIDGET_COMPONENTS[widget.type]"
                v-else-if="WIDGET_COMPONENTS[widget.type]"
                :widget="widget"
            />
        </div>
    </div>
</template>

<style scoped>
.kinetix-widgets-grid {
    display: grid;
    gap: var(--grid-gap-default, 1.5rem);
    grid-auto-flow: var(--grid-auto-flow, row);
    grid-template-columns: repeat(
        var(--grid-columns-default, 12),
        minmax(0, 1fr)
    );
}
.kinetix-grid-item {
    grid-column: span var(--col-span-default, 12) / span
        var(--col-span-default, 12);
}
@media (min-width: 640px) {
    .kinetix-widgets-grid {
        gap: var(--grid-gap-sm, var(--grid-gap-default, 1.5rem));
        grid-template-columns: repeat(
            var(--grid-columns-sm, var(--grid-columns-default, 12)),
            minmax(0, 1fr)
        );
    }
    .kinetix-grid-item {
        grid-column: span var(--col-span-sm, var(--col-span-default, 12)) / span
            var(--col-span-sm, var(--col-span-default, 12));
    }
}
@media (min-width: 768px) {
    .kinetix-widgets-grid {
        gap: var(
            --grid-gap-md,
            var(--grid-gap-sm, var(--grid-gap-default, 1.5rem))
        );
        grid-template-columns: repeat(
            var(
                --grid-columns-md,
                var(--grid-columns-sm, var(--grid-columns-default, 12))
            ),
            minmax(0, 1fr)
        );
    }
    .kinetix-grid-item {
        grid-column: span
            var(
                --col-span-md,
                var(--col-span-sm, var(--col-span-default, 12))
            ) /
            span
            var(--col-span-md, var(--col-span-sm, var(--col-span-default, 12)));
    }
}
@media (min-width: 1024px) {
    .kinetix-widgets-grid {
        gap: var(
            --grid-gap-lg,
            var(
                --grid-gap-md,
                var(--grid-gap-sm, var(--grid-gap-default, 1.5rem))
            )
        );
        grid-template-columns: repeat(
            var(
                --grid-columns-lg,
                var(
                    --grid-columns-md,
                    var(--grid-columns-sm, var(--grid-columns-default, 12))
                )
            ),
            minmax(0, 1fr)
        );
    }
    .kinetix-grid-item {
        grid-column: span
            var(
                --col-span-lg,
                var(
                    --col-span-md,
                    var(--col-span-sm, var(--col-span-default, 12))
                )
            ) /
            span
            var(
                --col-span-lg,
                var(
                    --col-span-md,
                    var(--col-span-sm, var(--col-span-default, 12))
                )
            );
    }
}
@media (min-width: 1280px) {
    .kinetix-widgets-grid {
        gap: var(
            --grid-gap-xl,
            var(
                --grid-gap-lg,
                var(
                    --grid-gap-md,
                    var(--grid-gap-sm, var(--grid-gap-default, 1.5rem))
                )
            )
        );
        grid-template-columns: repeat(
            var(
                --grid-columns-xl,
                var(
                    --grid-columns-lg,
                    var(
                        --grid-columns-md,
                        var(--grid-columns-sm, var(--grid-columns-default, 12))
                    )
                )
            ),
            minmax(0, 1fr)
        );
    }
    .kinetix-grid-item {
        grid-column: span
            var(
                --col-span-xl,
                var(
                    --col-span-lg,
                    var(
                        --col-span-md,
                        var(--col-span-sm, var(--col-span-default, 12))
                    )
                )
            ) /
            span
            var(
                --col-span-xl,
                var(
                    --col-span-lg,
                    var(
                        --col-span-md,
                        var(--col-span-sm, var(--col-span-default, 12))
                    )
                )
            );
    }
}
@media (min-width: 1536px) {
    .kinetix-widgets-grid {
        gap: var(
            --grid-gap-2xl,
            var(
                --grid-gap-xl,
                var(
                    --grid-gap-lg,
                    var(
                        --grid-gap-md,
                        var(--grid-gap-sm, var(--grid-gap-default, 1.5rem))
                    )
                )
            )
        );
        grid-template-columns: repeat(
            var(
                --grid-columns-2xl,
                var(
                    --grid-columns-xl,
                    var(
                        --grid-columns-lg,
                        var(
                            --grid-columns-md,
                            var(
                                --grid-columns-sm,
                                var(--grid-columns-default, 12)
                            )
                        )
                    )
                )
            ),
            minmax(0, 1fr)
        );
    }
    .kinetix-grid-item {
        grid-column: span
            var(
                --col-span-2xl,
                var(
                    --col-span-xl,
                    var(
                        --col-span-lg,
                        var(
                            --col-span-md,
                            var(--col-span-sm, var(--col-span-default, 12))
                        )
                    )
                )
            ) /
            span
            var(
                --col-span-2xl,
                var(
                    --col-span-xl,
                    var(
                        --col-span-lg,
                        var(
                            --col-span-md,
                            var(--col-span-sm, var(--col-span-default, 12))
                        )
                    )
                )
            );
    }
}
</style>
