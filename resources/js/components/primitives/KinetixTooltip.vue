<script setup lang="ts">
import {
    TooltipArrow,
    TooltipContent,
    TooltipPortal,
    TooltipProvider,
    TooltipRoot,
    TooltipTrigger,
} from 'reka-ui';

/**
 * A short hint on hover and keyboard focus for the one element it wraps
 * (which receives the trigger's props, so it should be focusable). Without
 * `text` it renders the element alone.
 */
withDefaults(
    defineProps<{
        text?: string | null;
        side?: 'top' | 'right' | 'bottom' | 'left';
    }>(),
    { text: null, side: 'top' },
);
</script>

<template>
    <slot v-if="!text" />
    <TooltipProvider v-else :delay-duration="300">
        <TooltipRoot>
            <TooltipTrigger as-child>
                <slot />
            </TooltipTrigger>
            <TooltipPortal>
                <TooltipContent
                    :side="side"
                    :side-offset="6"
                    :collision-padding="8"
                    class="max-w-64 px-3 py-1.5 text-xs data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 z-[var(--kinetix-z-popover,120)] w-fit rounded-md bg-foreground text-balance text-background"
                >
                    {{ text }}
                    <TooltipArrow class="fill-foreground" />
                </TooltipContent>
            </TooltipPortal>
        </TooltipRoot>
    </TooltipProvider>
</template>
