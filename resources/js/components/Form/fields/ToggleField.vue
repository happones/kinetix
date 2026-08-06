<script setup lang="ts">
import KinetixSwitch from '../../primitives/KinetixSwitch.vue';

defineProps<{ comp: any; value: any }>();

const emit = defineEmits<{ (e: 'update', value: any): void }>();
</script>

<template>
    <div class="py-1 flex items-center">
        <KinetixSwitch
            :model-value="!!value"
            :disabled="comp.isDisabled"
            @update:model-value="emit('update', $event)"
        />
    </div>
</template>
