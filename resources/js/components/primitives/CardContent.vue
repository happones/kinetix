<script setup lang="ts">
import { cn } from './cn';

// Mirrors shadcn-vue new-york-v4 `ui/card/CardContent.vue`.
const props = defineProps<{ class?: string }>();
</script>

<template>
    <div data-slot="card-content" :class="cn('px-6', props.class)">
        <slot />
    </div>
</template>
