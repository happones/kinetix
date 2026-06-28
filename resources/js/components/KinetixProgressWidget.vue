<script setup lang="ts">
import { computed } from 'vue';
import { statusTextClass } from '@/composables/useStatusColor';
import type { KinetixProgressData, KinetixWidget } from '@/types';
import Card from './primitives/Card.vue';
import CardContent from './primitives/CardContent.vue';
import WidgetHeaderActions from './widgets/WidgetHeaderActions.vue';

/**
 * A goal/quota progress panel: a value against a target rendered as a horizontal
 * bar (default) or a circular ring with the percentage in the center.
 */
const props = defineProps<{
    widget: KinetixWidget;
}>();

const data = computed<KinetixProgressData>(
    () =>
        props.widget.data ?? {
            value: 0,
            target: 100,
            percent: 0,
            display: '0%',
            caption: null,
            color: 'primary',
            ring: false,
        },
);

const percent = computed(() => Math.max(0, Math.min(100, data.value.percent)));

/** Solid fill class for the bar/ring (static strings keep Tailwind's JIT happy). */
const FILL: Record<string, string> = {
    success: 'bg-success',
    danger: 'bg-destructive',
    warning: 'bg-warning',
    info: 'bg-info',
    primary: 'bg-primary',
    gray: 'bg-muted-foreground',
};

const fillClass = computed(() => FILL[data.value.color] ?? FILL.primary);
const ringTextClass = computed(() => statusTextClass(data.value.color));

// Ring geometry — radius 42 in a 100×100 viewBox.
const RADIUS = 42;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;
const dashOffset = computed(
    () => CIRCUMFERENCE - (percent.value / 100) * CIRCUMFERENCE,
);
</script>

<template>
    <Card>
        <CardContent class="gap-4 flex flex-col">
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

            <!-- Ring variant -->
            <div
                v-if="data.ring"
                class="gap-4 py-2 flex flex-1 flex-col items-center justify-center"
            >
                <div class="size-32 relative">
                    <svg viewBox="0 0 100 100" class="size-full -rotate-90">
                        <circle
                            cx="50"
                            cy="50"
                            :r="RADIUS"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="9"
                            class="text-muted/60"
                        />
                        <circle
                            cx="50"
                            cy="50"
                            :r="RADIUS"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="9"
                            stroke-linecap="round"
                            :stroke-dasharray="CIRCUMFERENCE"
                            :stroke-dashoffset="dashOffset"
                            :class="ringTextClass"
                            class="ease-out transition-[stroke-dashoffset] duration-700"
                        />
                    </svg>
                    <div
                        class="inset-0 absolute flex flex-col items-center justify-center"
                    >
                        <span
                            class="text-2xl font-bold text-foreground tabular-nums"
                        >
                            {{ data.display }}
                        </span>
                    </div>
                </div>
                <p
                    v-if="data.caption"
                    class="text-xs text-center text-muted-foreground"
                >
                    {{ data.caption }}
                </p>
            </div>

            <!-- Bar variant -->
            <div v-else class="gap-2 flex flex-col">
                <div class="gap-3 flex items-baseline justify-between">
                    <span
                        class="text-2xl font-bold text-foreground tabular-nums"
                    >
                        {{ data.display }}
                    </span>
                    <span
                        v-if="data.caption"
                        class="text-xs text-muted-foreground"
                    >
                        {{ data.caption }}
                    </span>
                </div>
                <span class="h-2.5 overflow-hidden rounded-full bg-muted">
                    <span
                        class="ease-out block h-full rounded-full transition-[width] duration-700"
                        :class="fillClass"
                        :style="{ width: `${percent}%` }"
                    />
                </span>
            </div>
        </CardContent>
    </Card>
</template>
