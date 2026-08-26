<script setup lang="ts">
import type { KinetixAction } from '@/types/kinetix';
import KinetixActionBar from './KinetixActionBar.vue';

/**
 * A page-level header: title, optional description, and a right-aligned row of
 * actions — the standard place for "Create", "Edit", "Delete" or any custom
 * page action.
 *
 * It knows nothing about what the page renders below it, so it works the same
 * over a table, a form or a component of your own. The action row itself is
 * `KinetixActionBar`, shared with `KinetixPageFooter`, so confirmation modals,
 * dropdown groups, keyboard shortcuts and pending state behave identically in
 * both.
 *
 * Slots: `before-actions` (left of the action row) and the default slot (right
 * of it).
 */
withDefaults(
    defineProps<{
        heading?: string | null;
        description?: string | null;
        actions?: KinetixAction[];
    }>(),
    {
        heading: null,
        description: null,
        actions: () => [],
    },
);
</script>

<template>
    <div
        class="gap-3 sm:flex-row sm:items-center sm:justify-between mb-6 flex flex-col"
    >
        <div v-if="heading || description" class="min-w-0">
            <h1
                v-if="heading"
                class="text-xl font-semibold tracking-tight text-foreground"
            >
                {{ heading }}
            </h1>
            <p v-if="description" class="mt-1 text-sm text-muted-foreground">
                {{ description }}
            </p>
        </div>

        <KinetixActionBar :actions="actions">
            <template #before>
                <slot name="before-actions" />
            </template>
            <template #after>
                <slot />
            </template>
        </KinetixActionBar>
    </div>
</template>
