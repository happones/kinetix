<script setup lang="ts">
import { cn } from './cn';

// Mirrors shadcn-vue new-york-v4 `ui/card/CardDescription.vue`.
const props = defineProps<{ class?: string }>();
</script>

<template>
    <div
        data-slot="card-description"
        :class="cn('text-sm text-muted-foreground', props.class)"
    >
        <slot />
    </div>
</template>
