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
    statusSoftClass,
    statusTextClass,
} from '@/composables/useKinetixStatusColor';
import type { KinetixWidget, KinetixStat } from '@/types/kinetix';
import Card from './primitives/Card.vue';
import CardContent from './primitives/CardContent.vue';
import KinetixBadge from './primitives/KinetixBadge.vue';
import KinetixSparkline from './widgets/KinetixSparkline.vue';

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

/**
 * Sparklines inherit their color from the status TOKENS via `currentColor`
 * (stroke + gradient stops), so they shift with light/dark mode and any host
 * re-skin — no hardcoded hex.
 */
const sparklineColorClass = (color?: string) =>
    statusTextClass(color, 'text-muted-foreground');
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
                                <KinetixBadge
                                    v-if="stat.badge"
                                    :color="stat.badgeColor"
                                    size="sm"
                                    class="gap-0.5 shrink-0"
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
                                </KinetixBadge>
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

                        <!-- Sparkline Chart (colored by status token via currentColor) -->
                        <div
                            v-else-if="stat.chart && stat.chart.length >= 2"
                            class="mt-1 h-[40px] w-[120px] shrink-0"
                            :class="sparklineColorClass(stat.descriptionColor)"
                        >
                            <KinetixSparkline :data="stat.chart" />
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
