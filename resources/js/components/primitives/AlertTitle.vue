<script setup lang="ts">
import { cn } from './cn';

defineProps<{ class?: string }>();
</script>

<template>
    <h5
        data-slot="alert-title"
        :class="
            cn('mb-1 font-medium tracking-tight leading-none', $props.class)
        "
    >
        <slot />
    </h5>
</template>
