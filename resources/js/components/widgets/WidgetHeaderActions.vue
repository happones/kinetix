<script setup lang="ts">
import { resolveIcon } from '@/composables/useKinetixIcons';
import type { KinetixWidgetAction } from '@/types';

/**
 * Renders a widget's header actions (link/button) — e.g. "Export", "View all".
 */
defineProps<{
    actions?: KinetixWidgetAction[] | null;
}>();
</script>

<template>
    <div
        v-if="actions && actions.length"
        class="gap-2 flex shrink-0 items-center"
    >
        <a
            v-for="(action, idx) in actions"
            :key="idx"
            :href="action.url"
            class="gap-1.5 px-2.5 py-1.5 text-xs font-medium inline-flex items-center rounded-md border border-border text-foreground transition-colors hover:bg-accent"
        >
            <component
                :is="resolveIcon(action.icon)"
                v-if="action.icon && resolveIcon(action.icon)"
                class="size-3.5"
            />
            {{ action.label }}
        </a>
    </div>
</template>
