<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { resolveIcon } from '@/composables/useKinetixIcons';
import {
    statusSoftClass,
    statusTextClass,
} from '@/composables/useKinetixStatusColor';
import type { KinetixStatusColor } from '@/composables/useKinetixStatusColor';
import type { KinetixTableStat } from '@/types/kinetix';
import KinetixSparkline from '../widgets/KinetixSparkline.vue';

/**
 * KPI cards above a table (`Table::stats()`): label, aggregate value, an
 * optional icon badge, a colored trend chip and a sparkline. Values arrive
 * already aggregated and formatted by the server, so this only lays them out.
 */
const props = defineProps<{
    stats: KinetixTableStat[];
}>();

/** Sparkline tint: the trend color wins, then the card color, then muted. */
const sparklineColorClass = (stat: KinetixTableStat): string =>
    statusTextClass(
        (stat.descriptionColor ?? stat.color) as KinetixStatusColor,
        'text-muted-foreground',
    );
</script>

<template>
    <div
        v-if="props.stats.length > 0"
        class="kinetix-table-stats gap-4 mb-4 sm:grid-cols-2 lg:grid-cols-4 grid grid-cols-1"
    >
        <component
            v-for="(stat, index) in props.stats"
            :is="stat.url ? Link : 'div'"
            :key="index"
            :href="stat.url ?? undefined"
            class="gap-2 p-4 rounded-xl flex flex-col border border-border bg-card transition-shadow duration-200 motion-reduce:transition-none"
            :class="
                stat.url
                    ? 'hover:shadow-sm transition-all outline-none hover:bg-accent/50 focus-visible:ring-[3px] focus-visible:ring-ring/50'
                    : ''
            "
        >
            <div class="gap-4 flex items-start justify-between">
                <div class="min-w-0 flex-1">
                    <span
                        class="text-sm font-medium block truncate text-muted-foreground"
                    >
                        {{ stat.label }}
                    </span>
                    <span
                        class="text-2xl font-bold mt-1 tracking-tight block text-foreground tabular-nums"
                    >
                        {{ stat.value }}
                    </span>
                </div>

                <!-- Leading icon badge, or a sparkline when the card has one. -->
                <div
                    v-if="stat.icon && resolveIcon(stat.icon)"
                    class="size-10 rounded-xl flex shrink-0 items-center justify-center"
                    :class="statusSoftClass(stat.color as KinetixStatusColor)"
                >
                    <component :is="resolveIcon(stat.icon)" class="size-5" />
                </div>
                <div
                    v-else-if="stat.chart && stat.chart.length >= 2"
                    class="mt-1 h-[36px] w-[96px] shrink-0"
                    :class="sparklineColorClass(stat)"
                >
                    <KinetixSparkline
                        :data="stat.chart"
                        :width="96"
                        :height="36"
                    />
                </div>
            </div>

            <!-- Trend chip when a color is given; plain text otherwise. -->
            <div v-if="stat.description" class="flex">
                <span
                    v-if="stat.descriptionColor"
                    class="gap-1 px-2 py-0.5 text-xs font-semibold inline-flex max-w-full items-center rounded-full"
                    :class="
                        statusSoftClass(
                            stat.descriptionColor as KinetixStatusColor,
                        )
                    "
                >
                    <component
                        :is="resolveIcon(stat.descriptionIcon)"
                        v-if="
                            stat.descriptionIcon &&
                            resolveIcon(stat.descriptionIcon)
                        "
                        class="size-3.5 shrink-0"
                    />
                    <span class="truncate">{{ stat.description }}</span>
                </span>
                <span
                    v-else
                    class="text-xs gap-1 min-w-0 flex items-center text-muted-foreground"
                >
                    <component
                        :is="resolveIcon(stat.descriptionIcon)"
                        v-if="
                            stat.descriptionIcon &&
                            resolveIcon(stat.descriptionIcon)
                        "
                        class="size-3.5 shrink-0"
                    />
                    <span class="truncate">{{ stat.description }}</span>
                </span>
            </div>
        </component>
    </div>
</template>
