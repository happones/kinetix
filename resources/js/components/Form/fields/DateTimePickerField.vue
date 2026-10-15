<script setup lang="ts">
import { inputClass } from '@/composables/useKinetixShadcnVariants';
import KinetixDateTimePicker from '../../KinetixDateTimePicker.vue';

defineProps<{ comp: any; value: any }>();

const emit = defineEmits<{ (e: 'update', value: any): void }>();
</script>

<template>
    <!-- shadcn by default, native when ->native() -->
    <KinetixDateTimePicker
        v-if="comp.useCalendar"
        :id="comp.name"
        :value="value"
        :disabled="comp.isDisabled"
        :placeholder="comp.placeholder"
        :locale="comp.dateLocale"
        :minute-step="comp.minuteStep"
        :hour12="comp.hour12"
        :confirm="comp.confirm"
        :timezone="comp.timezone"
        :typed="comp.typed"
        :input-format="comp.inputFormat"
        :min-value="comp.minValue"
        :max-value="comp.maxValue"
        @update:value="emit('update', $event)"
    />
    <input
        v-else
        :id="comp.name"
        :value="value"
        type="datetime-local"
        :disabled="comp.isDisabled"
        :min="comp.minValue || undefined"
        :max="comp.maxValue || undefined"
        :class="inputClass"
        @input="emit('update', ($event.target as HTMLInputElement).value)"
    />
</template>
