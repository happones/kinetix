<script setup lang="ts">
import { cn } from './cn';

// Mirrors shadcn-vue new-york-v4 `ui/card/CardFooter.vue`.
const props = defineProps<{ class?: string }>();
</script>

<template>
    <div
        data-slot="card-footer"
        :class="cn('px-6 [.border-t]:pt-6 flex items-center', props.class)"
    >
        <slot />
    </div>
</template>
