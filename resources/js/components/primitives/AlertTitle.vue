<script setup lang="ts">
import { cn } from './cn';

withDefaults(
    defineProps<{
        class?: string;
        /**
         * The element to render. An alert's title is only a heading when it
         * fits the page's outline, so callers pick the level (`h2`…`h6`) or a
         * plain `p`; `h5` stays the default for existing call sites.
         */
        as?: string;
    }>(),
    { as: 'h5' },
);
</script>

<template>
    <component
        :is="as"
        data-slot="alert-title"
        :class="
            cn('mb-1 font-medium tracking-tight leading-none', $props.class)
        "
    >
        <slot />
    </component>
</template>
