<script setup lang="ts">
import { useKinetixFieldId } from '@/composables/useKinetixFieldId';
import KinetixSwitch from '../../primitives/KinetixSwitch.vue';

const fieldId = useKinetixFieldId();
defineProps<{ comp: any; value: any }>();

const emit = defineEmits<{ (e: 'update', value: any): void }>();
</script>

<template>
    <div class="py-1 flex items-center">
        <KinetixSwitch
            :id="fieldId(comp.name)"
            :model-value="!!value"
            :disabled="comp.isDisabled"
            @update:model-value="emit('update', $event)"
        />
    </div>
</template>
