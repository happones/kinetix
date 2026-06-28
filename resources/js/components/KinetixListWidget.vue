<script setup lang="ts">
import { computed } from 'vue';
import { resolveIcon } from '@/composables/useKinetixIcons';
import {
    statusBadgeClass,
    statusSoftClass,
} from '@/composables/useStatusColor';
import type { KinetixListItem, KinetixWidget } from '@/types';
import Card from './primitives/Card.vue';
import CardContent from './primitives/CardContent.vue';
import WidgetHeaderActions from './widgets/WidgetHeaderActions.vue';

/**
 * A list/feed widget: rows with a leading icon badge, title + subtitle, an
 * optional trailing value/badge and progress bar, and an optional footer link.
 * For recent activity, stock alerts, latest orders and similar dashboard panels.
 */
const props = defineProps<{
    widget: KinetixWidget;
}>();

const items = computed<KinetixListItem[]>(() => props.widget.data?.items ?? []);
const headerIcon = computed(() => props.widget.data?.icon ?? null);
const actionLabel = computed(() => props.widget.data?.actionLabel ?? null);
const actionUrl = computed(() => props.widget.data?.actionUrl ?? null);
const emptyState = computed(() => props.widget.data?.emptyState ?? null);
</script>

<template>
    <Card class="flex h-full flex-col">
        <CardContent class="gap-4 flex flex-1 flex-col">
            <div
                v-if="
                    widget.title ||
                    widget.description ||
                    widget.headerActions?.length
                "
                class="gap-2 flex items-start justify-between"
            >
                <div class="gap-2 min-w-0 flex items-start">
                    <component
                        :is="resolveIcon(headerIcon)"
                        v-if="headerIcon && resolveIcon(headerIcon)"
                        class="mt-0.5 size-5 shrink-0 text-muted-foreground"
                    />
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
                </div>
                <WidgetHeaderActions :actions="widget.headerActions" />
            </div>

            <p
                v-if="items.length === 0"
                class="py-6 text-sm text-center text-muted-foreground"
            >
                {{ emptyState ?? '—' }}
            </p>

            <ul v-else class="space-y-1 flex-1">
                <component
                    :is="item.url ? 'a' : 'div'"
                    v-for="(item, idx) in items"
                    :key="idx"
                    :href="item.url ?? undefined"
                    class="gap-3 rounded-lg px-2 py-2 flex items-center transition-colors"
                    :class="item.url ? 'hover:bg-accent/50' : ''"
                >
                    <span
                        v-if="item.icon && resolveIcon(item.icon)"
                        class="size-9 rounded-lg flex shrink-0 items-center justify-center"
                        :class="statusSoftClass(item.iconColor as any)"
                    >
                        <component
                            :is="resolveIcon(item.icon)"
                            class="size-4"
                        />
                    </span>

                    <span class="min-w-0 flex-1">
                        <span
                            class="text-sm font-medium block truncate text-foreground"
                            >{{ item.title }}</span
                        >
                        <span
                            v-if="item.subtitle"
                            class="text-xs block truncate text-muted-foreground"
                            >{{ item.subtitle }}</span
                        >
                        <span
                            v-if="
                                item.progress !== null &&
                                item.progress !== undefined
                            "
                            class="mt-1.5 h-1.5 block w-full overflow-hidden rounded-full bg-muted"
                        >
                            <span
                                class="block h-full rounded-full bg-primary"
                                :style="{ width: `${item.progress}%` }"
                            />
                        </span>
                    </span>

                    <span class="gap-1 flex shrink-0 flex-col items-end">
                        <span
                            v-if="item.value"
                            class="text-sm font-semibold text-foreground"
                            >{{ item.value }}</span
                        >
                        <span
                            v-if="item.badge"
                            class="px-2 py-0.5 text-xs font-medium rounded-full"
                            :class="statusBadgeClass(item.badgeColor as any)"
                            >{{ item.badge }}</span
                        >
                    </span>
                </component>
            </ul>

            <a
                v-if="actionUrl && actionLabel"
                :href="actionUrl"
                class="rounded-lg px-3 py-2 text-sm font-medium mt-auto block border border-border text-center text-foreground transition-colors hover:bg-accent"
            >
                {{ actionLabel }}
            </a>
        </CardContent>
    </Card>
</template>
