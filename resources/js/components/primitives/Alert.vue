<script setup lang="ts">
import { cn } from './cn';

const props = withDefaults(
    defineProps<{
        variant?: 'default' | 'destructive';
        class?: string;
        /**
         * `role="alert"` is an assertive live region — right for a one-off
         * message, wrong for a surface that rotates or is always present, which
         * would interrupt the screen reader on every change. Those pass their
         * own landmark role instead.
         */
        role?: string;
    }>(),
    {
        variant: 'default',
        role: 'alert',
    },
);
</script>

<template>
    <div
        data-slot="alert"
        :role="props.role"
        :class="
            cn(
                'rounded-lg px-4 py-3 text-sm [&>svg]:left-4 [&>svg]:top-4 relative w-full border [&>svg]:absolute [&>svg]:text-current [&>svg+div]:translate-y-[-3px]',
                props.variant === 'destructive' &&
                    'border-destructive/50 text-destructive dark:border-destructive [&>svg]:text-destructive/80',
                props.class,
            )
        "
    >
        <slot />
    </div>
</template>
