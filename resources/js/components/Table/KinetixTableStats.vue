<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { resolveIcon } from '@/composables/useKinetixIcons';
import { statusSoftClass } from '@/composables/useKinetixStatusColor';
import type { KinetixStatusColor } from '@/composables/useKinetixStatusColor';
import type { KinetixTableStat } from '@/types/kinetix';

/**
 * KPI cards above a table (`Table::stats()`): label, aggregate value and an
 * optional icon badge. Values arrive already aggregated and formatted by the
 * server, so this only lays them out.
 */
const props = defineProps<{
    stats: KinetixTableStat[];
}>();
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
            class="gap-4 p-4 rounded-xl flex items-start justify-between border border-border bg-card"
            :class="
                stat.url
                    ? 'transition-colors outline-none hover:bg-accent/50 focus-visible:ring-[3px] focus-visible:ring-ring/50'
                    : ''
            "
        >
            <div class="min-w-0 flex-1">
                <span
                    class="text-sm font-medium block truncate text-muted-foreground"
                >
                    {{ stat.label }}
                </span>
                <span
                    class="text-2xl font-bold mt-1 tracking-tight block text-foreground"
                >
                    {{ stat.value }}
                </span>
                <span
                    v-if="stat.description"
                    class="text-xs mt-1 block truncate text-muted-foreground"
                >
                    {{ stat.description }}
                </span>
            </div>

            <div
                v-if="stat.icon && resolveIcon(stat.icon)"
                class="size-10 rounded-xl flex shrink-0 items-center justify-center"
                :class="statusSoftClass(stat.color as KinetixStatusColor)"
            >
                <component :is="resolveIcon(stat.icon)" class="size-5" />
            </div>
        </component>
    </div>
</template>
