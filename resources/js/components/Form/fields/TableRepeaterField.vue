<script setup lang="ts">
import KinetixTableRepeater from '../../KinetixTableRepeater.vue';

defineProps<{ comp: any; value: any; errors: Record<string, string> }>();

const emit = defineEmits<{ (e: 'update', value: any): void }>();
</script>

<template>
    <KinetixTableRepeater
        :comp="comp"
        :model-value="value || []"
        :errors="errors"
        @update:model-value="emit('update', $event)"
    />
</template>
