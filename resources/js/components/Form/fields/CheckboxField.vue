<script setup lang="ts">
import { useKinetixFieldId } from '@/composables/useKinetixFieldId';
import KinetixCheckbox from '../../KinetixCheckbox.vue';

const fieldId = useKinetixFieldId();
defineProps<{ comp: any; value: any }>();

const emit = defineEmits<{ (e: 'update', value: any): void }>();
</script>

<template>
    <div class="space-x-2 py-1 flex items-center">
        <KinetixCheckbox
            :id="fieldId(comp.name)"
            :checked="!!value"
            :disabled="comp.isDisabled"
            @change="emit('update', $event)"
        />
    </div>
</template>
