<script setup lang="ts">
import { computed } from 'vue';
import { badgeVariants } from '@/composables/useKinetixShadcnVariants';
import type { BadgeVariant } from '@/composables/useKinetixShadcnVariants';
import { statusBadgeClass } from '@/composables/useKinetixStatusColor';
import type { KinetixStatusColor } from '@/composables/useKinetixStatusColor';

/**
 * The badge/pill primitive — the single home of the recipe; build on THIS,
 * never a re-copied class string. Two coloring modes:
 *
 * - `color` (Kinetix status color: primary/gray/success/warning/danger/info)
 *   → the soft tinted pill (`statusBadgeClass`), the look every status pill
 *   in the toolkit shares. `color` may be null/undefined at runtime — the
 *   muted fallback renders.
 * - `variant` (shadcn badge variant: default/secondary/destructive/outline)
 *   → the solid `badgeVariants()` recipe.
 *
 * `size="sm"` is the compact tab-badge size. Extra classes fall through.
 */
const props = withDefaults(
    defineProps<{
        color?: KinetixStatusColor | string | null;
        variant?: BadgeVariant | null;
        size?: 'default' | 'sm';
    }>(),
    { color: undefined, variant: null, size: 'default' },
);

const classes = computed(() => {
    if (props.variant) {
        return badgeVariants({ variant: props.variant });
    }

    return [
        'inline-flex items-center rounded-full font-semibold',
        props.size === 'sm'
            ? 'px-1.5 py-0.5 text-[11px]'
            : 'px-2 py-0.5 text-xs',
        statusBadgeClass((props.color ?? 'gray') as KinetixStatusColor),
    ].join(' ');
});
</script>

<template>
    <span :class="classes">
        <slot />
    </span>
</template>
