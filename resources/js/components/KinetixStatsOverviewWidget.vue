<script setup lang="ts">
import {
    TrendingUp,
    TrendingDown,
    ArrowUpRight,
    ArrowDownRight,
    ArrowUp,
    ArrowDown,
    Percent,
    DollarSign,
    Users,
    Activity,
    ShoppingCart,
    AlertCircle,
    Info,
    CheckCircle,
} from '@lucide/vue';
import { computed } from 'vue';
import { resolveIcon } from '@/composables/useKinetixIcons';
import {
    statusBadgeClass,
    statusSoftClass,
} from '@/composables/useStatusColor';
import type { KinetixWidget, KinetixStat } from '@/types';
import Card from './primitives/Card.vue';
import CardContent from './primitives/CardContent.vue';

const props = defineProps<{
    widget: KinetixWidget;
}>();

const stats = computed<KinetixStat[]>(() => {
    if (!props.widget.data || !Array.isArray(props.widget.data.stats)) {
        return [];
    }

    return props.widget.data.stats;
});

const iconMap: Record<string, any> = {
    'heroicon-m-arrow-trending-up': TrendingUp,
    'heroicon-m-arrow-trending-down': TrendingDown,
    'arrow-trending-up': TrendingUp,
    'arrow-trending-down': TrendingDown,
    'trending-up': TrendingUp,
    'trending-down': TrendingDown,
    'arrow-up-right': ArrowUpRight,
    'arrow-down-right': ArrowDownRight,
    'arrow-up': ArrowUp,
    'arrow-down': ArrowDown,
    percent: Percent,
    'dollar-sign': DollarSign,
    users: Users,
    activity: Activity,
    'shopping-cart': ShoppingCart,
    'alert-circle': AlertCircle,
    info: Info,
    'check-circle': CheckCircle,
};

const getStatIcon = (iconName?: string) => {
    if (!iconName) {
        return null;
    }

    const resolved = iconMap[iconName.toLowerCase()];

    if (resolved) {
        return resolved;
    }

    return null;
};

const getDescriptionColorClass = (color?: string) => statusSoftClass(color);

const getSparklineColor = (color?: string) => {
    if (color === 'success') {
        return {
            stroke: '#10b981',
            fill: 'url(#sparkline-grad-success)',
        };
    }

    if (color === 'danger') {
        return {
            stroke: '#f43f5e',
            fill: 'url(#sparkline-grad-danger)',
        };
    }

    if (color === 'warning') {
        return {
            stroke: '#f59e0b',
            fill: 'url(#sparkline-grad-warning)',
        };
    }

    if (color === 'info') {
        return {
            stroke: '#0ea5e9',
            fill: 'url(#sparkline-grad-info)',
        };
    }

    return {
        stroke: '#737373',
        fill: 'url(#sparkline-grad-gray)',
    };
};

const getSparklinePath = (chart?: number[], width = 120, height = 40) => {
    if (!chart || chart.length < 2) {
        return { line: '', area: '' };
    }

    const min = Math.min(...chart);
    const max = Math.max(...chart);
    const range = max - min === 0 ? 1 : max - min;
    const padding = 4;
    const usableHeight = height - padding * 2;

    const points = chart.map((val, index) => {
        const x = (index / (chart.length - 1)) * width;
        const y = height - padding - ((val - min) / range) * usableHeight;

        return { x, y };
    });

    const linePath = points
        .map((p, i) => {
            if (i === 0) {
                return `M ${p.x.toFixed(1)} ${p.y.toFixed(1)}`;
            }

            return `L ${p.x.toFixed(1)} ${p.y.toFixed(1)}`;
        })
        .join(' ');

    const last = points[points.length - 1];
    const first = points[0];
    const areaPath = `${linePath} L ${last.x.toFixed(1)} ${height} L ${first.x.toFixed(1)} ${height} Z`;

    return { line: linePath, area: areaPath };
};
</script>

<template>
    <div class="kinetix-stats-wrapper">
        <div v-if="widget.title || widget.description" class="mb-4">
            <h3
                v-if="widget.title"
                class="text-base font-semibold leading-6 text-foreground"
            >
                {{ widget.title }}
            </h3>
            <p
                v-if="widget.description"
                class="text-xs mt-1 text-muted-foreground"
            >
                {{ widget.description }}
            </p>
        </div>

        <div
            class="kinetix-stats-grid"
            :style="{ '--stats-cols': stats.length }"
        >
            <!-- Gradients Definitions -->
            <svg
                style="width: 0; height: 0; position: absolute"
                aria-hidden="true"
            >
                <defs>
                    <linearGradient
                        id="sparkline-grad-success"
                        x1="0"
                        y1="0"
                        x2="0"
                        y2="1"
                    >
                        <stop
                            offset="0%"
                            stop-color="#10b981"
                            stop-opacity="0.2"
                        />
                        <stop
                            offset="100%"
                            stop-color="#10b981"
                            stop-opacity="0"
                        />
                    </linearGradient>
                    <linearGradient
                        id="sparkline-grad-danger"
                        x1="0"
                        y1="0"
                        x2="0"
                        y2="1"
                    >
                        <stop
                            offset="0%"
                            stop-color="#f43f5e"
                            stop-opacity="0.2"
                        />
                        <stop
                            offset="100%"
                            stop-color="#f43f5e"
                            stop-opacity="0"
                        />
                    </linearGradient>
                    <linearGradient
                        id="sparkline-grad-warning"
                        x1="0"
                        y1="0"
                        x2="0"
                        y2="1"
                    >
                        <stop
                            offset="0%"
                            stop-color="#f59e0b"
                            stop-opacity="0.2"
                        />
                        <stop
                            offset="100%"
                            stop-color="#f59e0b"
                            stop-opacity="0"
                        />
                    </linearGradient>
                    <linearGradient
                        id="sparkline-grad-info"
                        x1="0"
                        y1="0"
                        x2="0"
                        y2="1"
                    >
                        <stop
                            offset="0%"
                            stop-color="#0ea5e9"
                            stop-opacity="0.2"
                        />
                        <stop
                            offset="100%"
                            stop-color="#0ea5e9"
                            stop-opacity="0"
                        />
                    </linearGradient>
                    <linearGradient
                        id="sparkline-grad-gray"
                        x1="0"
                        y1="0"
                        x2="0"
                        y2="1"
                    >
                        <stop
                            offset="0%"
                            stop-color="#737373"
                            stop-opacity="0.15"
                        />
                        <stop
                            offset="100%"
                            stop-color="#737373"
                            stop-opacity="0"
                        />
                    </linearGradient>
                </defs>
            </svg>

            <Card
                v-for="(stat, index) in stats"
                :key="index"
                class="kinetix-stat-card hover:shadow-md hover:-translate-y-0.5 group relative overflow-hidden transition-all duration-300"
            >
                <CardContent class="gap-4 flex flex-col">
                    <div class="gap-4 flex items-start justify-between">
                        <div class="min-w-0 flex-1">
                            <span
                                class="gap-2 flex items-center justify-between"
                            >
                                <span
                                    class="text-sm font-medium truncate text-muted-foreground"
                                >
                                    {{ stat.label }}
                                </span>
                                <span
                                    v-if="stat.badge"
                                    class="gap-0.5 px-1.5 py-0.5 text-xs font-medium inline-flex shrink-0 items-center rounded-full"
                                    :class="
                                        statusBadgeClass(stat.badgeColor as any)
                                    "
                                >
                                    <component
                                        :is="getStatIcon(stat.descriptionIcon)"
                                        v-if="
                                            stat.descriptionIcon &&
                                            getStatIcon(stat.descriptionIcon)
                                        "
                                        class="size-3"
                                    />
                                    {{ stat.badge }}
                                </span>
                            </span>
                            <span
                                class="text-3xl font-bold mt-2 tracking-tight block text-foreground"
                            >
                                {{ stat.value }}
                            </span>
                        </div>

                        <!-- Leading icon badge -->
                        <div
                            v-if="stat.icon && resolveIcon(stat.icon)"
                            class="size-11 rounded-xl flex shrink-0 items-center justify-center"
                            :class="
                                getDescriptionColorClass(
                                    stat.iconColor ?? undefined,
                                )
                            "
                        >
                            <component
                                :is="resolveIcon(stat.icon)"
                                class="size-5"
                            />
                        </div>

                        <!-- Sparkline Chart -->
                        <div
                            v-else-if="stat.chart && stat.chart.length >= 2"
                            class="mt-1 h-[40px] w-[120px] shrink-0"
                        >
                            <svg
                                class="h-full w-full overflow-visible"
                                viewBox="0 0 120 40"
                            >
                                <path
                                    :d="
                                        getSparklinePath(stat.chart, 120, 40)
                                            .area
                                    "
                                    :fill="
                                        getSparklineColor(stat.descriptionColor)
                                            .fill
                                    "
                                    stroke="none"
                                />
                                <path
                                    :d="
                                        getSparklinePath(stat.chart, 120, 40)
                                            .line
                                    "
                                    fill="none"
                                    :stroke="
                                        getSparklineColor(stat.descriptionColor)
                                            .stroke
                                    "
                                    stroke-width="2"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />
                            </svg>
                        </div>
                    </div>

                    <!-- Description / Trend Badge -->
                    <div
                        v-if="stat.description"
                        class="gap-1.5 flex flex-wrap items-center"
                    >
                        <span
                            class="gap-1 px-2 py-0.5 text-xs font-semibold inline-flex shrink-0 items-center rounded-full"
                            :class="
                                getDescriptionColorClass(stat.descriptionColor)
                            "
                        >
                            <component
                                :is="getStatIcon(stat.descriptionIcon)"
                                v-if="
                                    stat.descriptionIcon &&
                                    getStatIcon(stat.descriptionIcon)
                                "
                                class="w-3.5 h-3.5"
                            />
                            {{ stat.description }}
                        </span>
                    </div>

                    <!-- Footer link -->
                    <a
                        v-if="stat.linkUrl && stat.linkLabel"
                        :href="stat.linkUrl"
                        class="gap-1 text-sm font-medium inline-flex items-center text-primary transition-opacity hover:opacity-80"
                    >
                        {{ stat.linkLabel }}
                        <ArrowUpRight class="size-3.5" />
                    </a>
                </CardContent>
            </Card>
        </div>
    </div>
</template>

<style scoped>
/*
 * Container queries (not viewport media queries) so the stat-card columns
 * respond to this widget's OWN rendered width — critical once it can live
 * inside a narrow masonry column or a small columnSpan, where the viewport
 * may be wide (desktop) while the widget itself is only a few hundred px.
 */
.kinetix-stats-wrapper {
    container-type: inline-size;
}
.kinetix-stats-grid {
    display: grid;
    gap: 1.5rem;
    grid-template-columns: repeat(1, minmax(0, 1fr));
}
@container (min-width: 480px) {
    .kinetix-stats-grid {
        grid-template-columns: repeat(
            min(var(--stats-cols, 3), 2),
            minmax(0, 1fr)
        );
    }
}
@container (min-width: 800px) {
    .kinetix-stats-grid {
        grid-template-columns: repeat(var(--stats-cols, 3), minmax(0, 1fr));
    }
}
</style>
