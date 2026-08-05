<script setup lang="ts">
import { Loader2 } from '@lucide/vue';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';
import type {
    ButtonSize,
    ButtonVariant,
} from '@/composables/useKinetixShadcnVariants';
import { cn } from './primitives/cn';

/**
 * Kinetix's base button: shadcn-vue (new-york) styling on the token contract,
 * with a built-in pending state. While `loading` is true the button disables
 * itself and a spinner replaces the `icon` slot — every Kinetix surface that
 * fires an action (table toolbars, page headers, scaffolded create/edit pages)
 * renders through this, so double-click protection and pending feedback can't
 * diverge per call site.
 *
 * Slots: `icon` (leading icon, swapped for the spinner while loading) and the
 * default slot (label / trailing content).
 */
const props = withDefaults(
    defineProps<{
        variant?: ButtonVariant;
        size?: ButtonSize;
        type?: 'button' | 'submit' | 'reset';
        /** Pending state: disables the button and shows a spinner. */
        loading?: boolean;
        disabled?: boolean;
    }>(),
    {
        variant: 'default',
        size: 'default',
        type: 'button',
        loading: false,
        disabled: false,
    },
);
</script>

<template>
    <button
        :type="props.type"
        :disabled="props.disabled || props.loading"
        :aria-busy="props.loading || undefined"
        :class="
            cn(
                buttonVariants({ variant: props.variant, size: props.size }),
                'cursor-pointer',
            )
        "
    >
        <Loader2 v-if="props.loading" class="h-4 w-4 animate-spin" />
        <slot v-else name="icon" />
        <slot />
    </button>
</template>
