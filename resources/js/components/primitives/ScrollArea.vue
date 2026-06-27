<script setup lang="ts">
import { ScrollAreaCorner, ScrollAreaRoot, ScrollAreaViewport } from 'reka-ui';
import { cn } from './cn';
import ScrollBar from './ScrollBar.vue';

// Mirrors shadcn-vue new-york-v4 `ui/scroll-area/ScrollArea.vue`.
const props = withDefaults(
    defineProps<{
        class?: string;
        /** Reka scrollbar visibility behaviour. */
        type?: 'auto' | 'always' | 'scroll' | 'hover';
        /** Extra classes for the inner viewport. */
        viewportClass?: string;
    }>(),
    { type: 'hover' },
);
</script>

<template>
    <ScrollAreaRoot
        data-slot="scroll-area"
        :type="type"
        :class="cn('relative', props.class)"
    >
        <ScrollAreaViewport
            data-slot="scroll-area-viewport"
            :class="
                cn(
                    'size-full rounded-[inherit] transition-[color,box-shadow] outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-1',
                    viewportClass,
                )
            "
        >
            <slot />
        </ScrollAreaViewport>
        <ScrollBar />
        <ScrollAreaCorner />
    </ScrollAreaRoot>
</template>
