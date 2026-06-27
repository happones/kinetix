<script setup lang="ts">
import { cn } from './cn';

// Mirrors shadcn-vue new-york-v4 `ui/card/CardHeader.vue`.
const props = defineProps<{ class?: string }>();
</script>

<template>
    <div
        data-slot="card-header"
        :class="
            cn(
                'gap-1.5 px-6 [.border-b]:pb-6 @container/card-header grid auto-rows-min grid-rows-[auto_auto] items-start has-data-[slot=card-action]:grid-cols-[1fr_auto]',
                props.class,
            )
        "
    >
        <slot />
    </div>
</template>
