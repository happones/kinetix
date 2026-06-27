<script setup lang="ts">
import { Activity, AlertTriangle, Clock, Layers } from '@lucide/vue';
import { computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixQueue } from '@/composables/useKinetixQueue';

/**
 * A compact, live queue-health widget — throughput, recent & failed jobs, and
 * pending depth per queue. Reads Horizon's metrics when installed (with a status
 * badge), otherwise queue sizes + failed_jobs. Polls on the shared interval.
 * Drop it in a Kinetix dashboard; it complements (doesn't replace) Horizon.
 */
const { t } = useI18n();
const { snapshot, failed, start } = useKinetixQueue();

onMounted(start);

const pending = computed(() =>
    (snapshot.value?.queues ?? []).reduce((sum, q) => sum + q.size, 0),
);

const statusClass = computed(() => {
    switch (snapshot.value?.status) {
        case 'running':
            return 'bg-green-500/10 text-green-600 dark:text-green-400';
        case 'paused':
            return 'bg-amber-500/10 text-amber-600 dark:text-amber-400';
        default:
            return 'bg-muted text-muted-foreground';
    }
});

interface Tile {
    key: string;
    label: string;
    value: number;
    icon: unknown;
    tone: string;
}

const tiles = computed<Tile[]>(() => {
    const s = snapshot.value;
    const list: Tile[] = [];

    if (s?.throughput !== null && s?.throughput !== undefined) {
        list.push({
            key: 'throughput',
            label: t('kinetix.queue_throughput'),
            value: s.throughput,
            icon: Activity,
            tone: 'text-foreground',
        });
    }

    if (s?.recentJobs !== null && s?.recentJobs !== undefined) {
        list.push({
            key: 'recent',
            label: t('kinetix.queue_recent'),
            value: s.recentJobs,
            icon: Clock,
            tone: 'text-foreground',
        });
    }

    list.push({
        key: 'pending',
        label: t('kinetix.queue_pending'),
        value: pending.value,
        icon: Layers,
        tone: 'text-foreground',
    });
    list.push({
        key: 'failed',
        label: t('kinetix.queue_failed'),
        value: s?.failedJobs ?? 0,
        icon: AlertTriangle,
        tone:
            (s?.failedJobs ?? 0) > 0
                ? 'text-red-600 dark:text-red-400'
                : 'text-foreground',
    });

    return list;
});
</script>

<template>
    <div class="rounded-xl p-4 shadow-sm border border-border bg-card">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-foreground">
                {{ t('kinetix.queue_title') }}
            </h3>
            <span
                v-if="snapshot?.horizon && snapshot?.status"
                class="px-2 py-0.5 text-xs font-medium rounded-full capitalize"
                :class="statusClass"
            >
                {{ t(`kinetix.queue_status_${snapshot.status}`) }}
            </span>
        </div>

        <p v-if="failed && !snapshot" class="text-sm text-muted-foreground">
            {{ t('kinetix.queue_unavailable') }}
        </p>

        <template v-else>
            <div class="gap-3 sm:grid-cols-4 grid grid-cols-2">
                <div
                    v-for="tile in tiles"
                    :key="tile.key"
                    class="rounded-lg p-3 border border-border bg-background"
                >
                    <div
                        class="mb-1 gap-1.5 text-xs flex items-center text-muted-foreground"
                    >
                        <component :is="tile.icon" class="size-3.5" />
                        {{ tile.label }}
                    </div>
                    <div
                        class="text-2xl font-semibold tabular-nums"
                        :class="tile.tone"
                    >
                        {{ tile.value }}
                    </div>
                </div>
            </div>

            <div v-if="snapshot?.queues?.length" class="mt-4 space-y-1">
                <div
                    v-for="q in snapshot.queues"
                    :key="`${q.connection}:${q.name}`"
                    class="px-2 py-1.5 text-sm flex items-center justify-between rounded-md hover:bg-accent/50"
                >
                    <span class="font-medium text-foreground">{{
                        q.name
                    }}</span>
                    <span class="gap-3 flex items-center text-muted-foreground">
                        <span v-if="q.wait !== null" class="text-xs">
                            {{ t('kinetix.queue_wait', { seconds: q.wait }) }}
                        </span>
                        <span class="tabular-nums">{{ q.size }}</span>
                    </span>
                </div>
            </div>
        </template>
    </div>
</template>
