<script setup lang="ts">
import { cn } from './cn';

defineProps<{ class?: string }>();
</script>

<template>
    <div
        data-slot="alert-description"
        :class="cn('text-sm [&_p]:leading-relaxed', $props.class)"
    >
        <slot />
    </div>
</template>
