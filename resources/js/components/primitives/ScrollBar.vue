<script setup lang="ts">
import { ScrollAreaScrollbar, ScrollAreaThumb } from 'reka-ui';
import { cn } from './cn';

// Mirrors shadcn-vue new-york-v4 `ui/scroll-area/ScrollBar.vue`.
const props = withDefaults(
    defineProps<{
        orientation?: 'vertical' | 'horizontal';
        class?: string;
    }>(),
    { orientation: 'vertical' },
);
</script>

<template>
    <ScrollAreaScrollbar
        data-slot="scroll-area-scrollbar"
        :orientation="orientation"
        :class="
            cn(
                'flex touch-none p-px transition-colors select-none',
                orientation === 'vertical' &&
                    'w-2.5 h-full border-l border-l-transparent',
                orientation === 'horizontal' &&
                    'h-2.5 flex-col border-t border-t-transparent',
                props.class,
            )
        "
    >
        <ScrollAreaThumb
            data-slot="scroll-area-thumb"
            class="relative flex-1 rounded-full bg-border"
        />
    </ScrollAreaScrollbar>
</template>
