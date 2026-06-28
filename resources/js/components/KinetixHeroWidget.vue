<script setup lang="ts">
import { computed } from 'vue';
import { statusTextClass } from '@/composables/useStatusColor';
import type { KinetixWidget } from '@/types';
import Card from './primitives/Card.vue';
import CardContent from './primitives/CardContent.vue';

/**
 * A prominent hero / call-to-action card: a greeting + headline value with a
 * delta and a primary button — e.g. a "Congratulations, best seller!" panel.
 */
const props = defineProps<{
    widget: KinetixWidget;
}>();

const d = computed(() => props.widget.data ?? {});
</script>

<template>
    <Card
        class="overflow-hidden"
        :class="
            d.gradient
                ? 'border-primary/20 bg-gradient-to-br from-primary/10 via-card to-card'
                : ''
        "
    >
        <CardContent class="gap-4 flex flex-wrap items-end justify-between">
            <div class="min-w-0">
                <h3
                    v-if="widget.title"
                    class="text-lg font-bold tracking-tight text-foreground"
                >
                    {{ widget.title }}
                </h3>
                <p
                    v-if="d.subtitle"
                    class="mt-0.5 text-sm text-muted-foreground"
                >
                    {{ d.subtitle }}
                </p>
                <div
                    v-if="d.value"
                    class="mt-3 text-3xl font-bold tracking-tight text-foreground"
                >
                    {{ d.value }}
                </div>
                <p
                    v-if="d.delta"
                    class="mt-1 text-xs font-medium"
                    :class="statusTextClass(d.deltaColor)"
                >
                    {{ d.delta }}
                </p>
            </div>

            <a
                v-if="d.actionUrl && d.actionLabel"
                :href="d.actionUrl"
                class="rounded-lg px-4 py-2 text-sm font-medium shrink-0 border border-border bg-background text-foreground transition-colors hover:bg-accent"
            >
                {{ d.actionLabel }}
            </a>
        </CardContent>
    </Card>
</template>
