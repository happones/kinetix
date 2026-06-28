<script setup lang="ts">
import { Star } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { KinetixRatingLevel, KinetixWidget } from '@/types';
import Card from './primitives/Card.vue';
import CardContent from './primitives/CardContent.vue';
import WidgetHeaderActions from './widgets/WidgetHeaderActions.vue';

/**
 * A ratings summary: average score + stars on the left, a per-level breakdown
 * with bars + counts on the right. Like a product "Customer Reviews" panel.
 */
const props = defineProps<{
    widget: KinetixWidget;
}>();

const { t } = useI18n();

const average = computed<number>(() => props.widget.data?.average ?? 0);
const total = computed<number>(() => props.widget.data?.total ?? 0);
const max = computed<number>(() => props.widget.data?.max ?? 5);
const breakdown = computed<KinetixRatingLevel[]>(
    () => props.widget.data?.breakdown ?? [],
);

const stars = computed(() =>
    Array.from({ length: max.value }, (_, i) =>
        Math.max(0, Math.min(1, average.value - i)),
    ),
);

function barColor(level: number): string {
    if (level >= 4) {
        return 'bg-green-500';
    }

    if (level === 3) {
        return 'bg-amber-400';
    }

    if (level === 2) {
        return 'bg-orange-500';
    }

    return 'bg-red-500';
}

const formatCount = (value: number): string => value.toLocaleString();
</script>

<template>
    <Card>
        <CardContent class="gap-5 flex flex-col">
            <div
                v-if="widget.title || widget.headerActions?.length"
                class="gap-3 flex items-start justify-between"
            >
                <div class="min-w-0">
                    <h3
                        v-if="widget.title"
                        class="text-base font-semibold leading-6 text-foreground"
                    >
                        {{ widget.title }}
                    </h3>
                    <p
                        v-if="widget.description"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        {{ widget.description }}
                    </p>
                </div>
                <WidgetHeaderActions :actions="widget.headerActions" />
            </div>

            <div class="gap-6 flex items-center">
                <!-- Average + stars -->
                <div class="shrink-0 text-center">
                    <div class="text-4xl font-bold text-foreground">
                        {{ average }}
                    </div>
                    <div class="mt-1 gap-0.5 flex items-center justify-center">
                        <span
                            v-for="(fill, i) in stars"
                            :key="i"
                            class="relative inline-block"
                        >
                            <Star class="size-4 text-muted-foreground/30" />
                            <span
                                class="inset-0 absolute overflow-hidden"
                                :style="{ width: `${fill * 100}%` }"
                            >
                                <Star
                                    class="size-4 fill-amber-400 text-amber-400"
                                />
                            </span>
                        </span>
                    </div>
                    <div class="mt-1 text-xs text-muted-foreground">
                        {{ t('kinetix.rating_out_of', { max }) }}
                    </div>
                </div>

                <!-- Breakdown bars -->
                <div class="min-w-0 space-y-1.5 flex-1">
                    <div
                        v-for="row in breakdown"
                        :key="row.level"
                        class="gap-2 text-xs flex items-center"
                    >
                        <span
                            class="w-8 gap-0.5 flex shrink-0 items-center text-muted-foreground"
                        >
                            {{ row.level }}
                            <Star
                                class="size-3 fill-amber-400 text-amber-400"
                            />
                        </span>
                        <span
                            class="h-2 flex-1 overflow-hidden rounded-full bg-muted"
                        >
                            <span
                                class="block h-full rounded-full"
                                :class="barColor(row.level)"
                                :style="{ width: `${row.pct}%` }"
                            />
                        </span>
                        <span
                            class="w-12 shrink-0 text-right text-muted-foreground tabular-nums"
                            >{{ formatCount(row.count) }}</span
                        >
                    </div>
                </div>
            </div>

            <p v-if="total" class="text-xs text-muted-foreground">
                {{ t('kinetix.rating_reviews', { total: formatCount(total) }) }}
            </p>
        </CardContent>
    </Card>
</template>
