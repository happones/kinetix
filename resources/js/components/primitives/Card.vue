<script setup lang="ts">
import { cn } from './cn';

// Mirrors shadcn-vue new-york-v4 `ui/card/Card.vue`.
const props = defineProps<{ class?: string }>();
</script>

<template>
    <div
        data-slot="card"
        :class="
            cn(
                'gap-6 rounded-xl py-6 shadow-sm flex flex-col border border-border bg-card text-card-foreground',
                props.class,
            )
        "
    >
        <slot />
    </div>
</template>
