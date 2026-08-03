<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    statusFillClass,
    statusTextClass,
} from '@/composables/useKinetixStatusColor';
import type { KinetixUsageMetricData } from '@/types/kinetix';
import Card from './primitives/Card.vue';
import CardContent from './primitives/CardContent.vue';
import CardHeader from './primitives/CardHeader.vue';
import CardTitle from './primitives/CardTitle.vue';

/**
 * Metered-usage progress meters for the billing page — one bar per dimension
 * reported by `BillingManager::usage()` (API calls, seats, storage, …).
 * Renders nothing when `metrics` is empty, so it's safe to always mount
 * alongside `KinetixSubscriptionStatus`:
 *
 *     <KinetixUsageMeters :metrics="usage" />
 *
 * The limit, percent and color are all resolved server-side (from the plan's
 * `features.usage.*` and the app's own `meteredUsage()` logic) — this
 * component only renders what it's given.
 */
const props = withDefaults(
    defineProps<{
        metrics?: KinetixUsageMetricData[] | null;
        title?: string | null;
    }>(),
    { metrics: () => [], title: null },
);

const { t } = useI18n();

// Guards against `metrics` arriving as `null`/omitted (e.g. the controller's
// usage() call failing) in addition to the withDefaults() fallback, so a
// transient server-side hiccup never blanks the whole billing page.
const metrics = computed(() => props.metrics ?? []);

const percentFor = (metric: KinetixUsageMetricData) =>
    Math.max(0, Math.min(100, metric.percent));
</script>

<template>
    <Card v-if="metrics.length > 0">
        <CardHeader>
            <CardTitle>{{
                props.title ?? t('kinetix.billing_usage_title')
            }}</CardTitle>
        </CardHeader>

        <CardContent class="gap-5 flex flex-col">
            <div
                v-for="metric in metrics"
                :key="metric.key"
                class="gap-1.5 flex flex-col"
            >
                <div class="gap-3 flex items-baseline justify-between">
                    <span class="text-sm font-medium text-foreground">{{
                        metric.label
                    }}</span>
                    <span class="text-xs text-muted-foreground tabular-nums">
                        {{ metric.display }}
                    </span>
                </div>
                <span class="h-2 overflow-hidden rounded-full bg-muted">
                    <span
                        class="ease-out block h-full rounded-full transition-[width] duration-700"
                        :class="statusFillClass(metric.color)"
                        :style="{ width: `${percentFor(metric)}%` }"
                    />
                </span>
                <p
                    v-if="metric.overLimit"
                    class="text-xs font-medium"
                    :class="statusTextClass(metric.color)"
                >
                    {{ t('kinetix.billing_usage_over_limit') }}
                </p>
            </div>
        </CardContent>
    </Card>
</template>
