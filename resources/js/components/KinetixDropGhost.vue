<script setup lang="ts">
/**
 * A placeholder rendered inside a drop target while a drag hovers it,
 * previewing where the dragged item will land (kanban columns, calendar
 * cells/slots). Purely decorative — it is aria-hidden and pointer-transparent
 * so it never disturbs the surface's drag events, announcements, or keyboard
 * flows.
 */
withDefaults(
    defineProps<{
        /** Text preview of the dragged item (e.g. its title). */
        label?: string | null;
        /** Chip-sized (calendar event chips) instead of card-sized (kanban). */
        compact?: boolean;
    }>(),
    { label: null, compact: false },
);
</script>

<template>
    <div
        aria-hidden="true"
        class="kx-drop-ghost pointer-events-none border-2 border-dashed border-primary/40 bg-primary/5"
        :class="compact ? 'rounded px-1.5 py-0.5' : 'p-3 rounded-md'"
    >
        <p
            class="text-xs truncate text-muted-foreground"
            :class="compact ? '' : 'font-medium'"
        >
            {{ label || ' ' }}
        </p>
    </div>
</template>

<style scoped>
.kx-drop-ghost {
    animation: kx-drop-ghost-in 150ms ease-out;
}

@keyframes kx-drop-ghost-in {
    from {
        opacity: 0;
        transform: scale(0.97);
    }

    to {
        opacity: 1;
        transform: scale(1);
    }
}

@media (prefers-reduced-motion: reduce) {
    .kx-drop-ghost {
        animation: none;
    }
}
</style>
