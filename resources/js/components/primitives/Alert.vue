<script setup lang="ts">
import { cn } from './cn';

const props = withDefaults(
    defineProps<{
        variant?: 'default' | 'destructive';
        class?: string;
    }>(),
    {
        variant: 'default',
    },
);
</script>

<template>
    <div
        data-slot="alert"
        role="alert"
        :class="
            cn(
                'relative w-full rounded-lg border px-4 py-3 text-sm [&>svg]:text-current [&>svg+div]:translate-y-[-3px] [&>svg]:absolute [&>svg]:left-4 [&>svg]:top-4',
                props.variant === 'destructive' &&
                    'border-destructive/50 text-destructive dark:border-destructive [&>svg]:text-destructive/80',
                props.class,
            )
        "
    >
        <slot />
    </div>
</template>
