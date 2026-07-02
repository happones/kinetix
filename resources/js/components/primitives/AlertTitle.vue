<script setup lang="ts">
import { cn } from './cn';

defineProps<{ class?: string }>();
</script>

<template>
    <h5
        data-slot="alert-title"
        :class="cn('mb-1 font-medium leading-none tracking-tight', $props.class)"
    >
        <slot />
    </h5>
</template>
