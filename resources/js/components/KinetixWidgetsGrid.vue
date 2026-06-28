<script setup lang="ts">
import type { KinetixWidget, KinetixWidgetsGridData } from '@/types';
import KinetixChartWidget from './KinetixChartWidget.vue';
import KinetixCustomWidget from './KinetixCustomWidget.vue';
import KinetixHeroWidget from './KinetixHeroWidget.vue';
import KinetixListWidget from './KinetixListWidget.vue';
import KinetixProgressWidget from './KinetixProgressWidget.vue';
import KinetixRatingWidget from './KinetixRatingWidget.vue';
import KinetixStatsOverviewWidget from './KinetixStatsOverviewWidget.vue';
import KinetixTableWidget from './KinetixTableWidget.vue';

const props = defineProps<{
    grid: KinetixWidgetsGridData;
}>();

const getGridStyle = (columns: any) => {
    const style: Record<string, string> = {};

    if (typeof columns === 'number' || typeof columns === 'string') {
        style['--grid-columns-default'] = `${columns}`;

        return style;
    }

    if (typeof columns === 'object' && columns !== null) {
        const cols = columns as Record<string, number | string>;
        style['--grid-columns-default'] = `${cols.default ?? 12}`;

        if (cols.sm !== undefined) {
            style['--grid-columns-sm'] = `${cols.sm}`;
        }

        if (cols.md !== undefined) {
            style['--grid-columns-md'] = `${cols.md}`;
        }

        if (cols.lg !== undefined) {
            style['--grid-columns-lg'] = `${cols.lg}`;
        }

        if (cols.xl !== undefined) {
            style['--grid-columns-xl'] = `${cols.xl}`;
        }

        if (cols['2xl'] !== undefined) {
            style['--grid-columns-2xl'] = `${cols['2xl']}`;
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
    <div
        class="kinetix-widgets-grid w-full"
        :style="getGridStyle(grid.columns)"
    >
        <div
            v-for="widget in grid.widgets"
            :key="widget.id"
            class="kinetix-grid-item"
            :style="getItemStyle(widget)"
        >
            <KinetixStatsOverviewWidget
                v-if="widget.type === 'stats'"
                :widget="widget"
            />
            <KinetixChartWidget
                v-else-if="widget.type === 'chart'"
                :widget="widget"
            />
            <KinetixTableWidget
                v-else-if="widget.type === 'table'"
                :widget="widget"
            />
            <KinetixListWidget
                v-else-if="widget.type === 'list'"
                :widget="widget"
            />
            <KinetixRatingWidget
                v-else-if="widget.type === 'rating'"
                :widget="widget"
            />
            <KinetixProgressWidget
                v-else-if="widget.type === 'progress'"
                :widget="widget"
            />
            <KinetixHeroWidget
                v-else-if="widget.type === 'hero'"
                :widget="widget"
            />
            <KinetixCustomWidget
                v-else-if="widget.type === 'custom'"
                :widget="widget"
            >
                <slot :name="widget.id" :widget="widget" />
            </KinetixCustomWidget>
        </div>
    </div>
</template>

<style scoped>
.kinetix-widgets-grid {
    display: grid;
    gap: 1.5rem;
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
