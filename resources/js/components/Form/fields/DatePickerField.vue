<script setup lang="ts">
import { useKinetixFieldId } from '@/composables/useKinetixFieldId';
import { inputClass } from '@/composables/useKinetixShadcnVariants';
import KinetixDatePicker from '../../KinetixDatePicker.vue';

const fieldId = useKinetixFieldId();
defineProps<{ comp: any; value: any }>();

const emit = defineEmits<{ (e: 'update', value: any): void }>();
</script>

<template>
    <!-- shadcn calendar by default, native when ->native() -->
    <KinetixDatePicker
        v-if="comp.useCalendar"
        :id="fieldId(comp.name)"
        :value="value"
        :disabled="comp.isDisabled"
        :placeholder="comp.placeholder"
        :locale="comp.dateLocale"
        :confirm="comp.confirm"
        :show-today="comp.showToday"
        :close-on-select="comp.closeOnSelect ?? true"
        :timezone="comp.timezone"
        :typed="comp.typed"
        :input-format="comp.inputFormat"
        :min-value="comp.minValue"
        :max-value="comp.maxValue"
        @update:value="emit('update', $event)"
    />
    <input
        v-else
        :id="fieldId(comp.name)"
        :value="value"
        type="date"
        :disabled="comp.isDisabled"
        :min="comp.minValue || undefined"
        :max="comp.maxValue || undefined"
        :class="inputClass"
        @input="emit('update', ($event.target as HTMLInputElement).value)"
    />
</template>
