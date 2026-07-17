<script setup lang="ts">
defineProps<{ comp: any; value: any }>();
</script>

<template>
    <input :value="value" type="hidden" />
</template>
