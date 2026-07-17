<script setup lang="ts">
import { inputClass } from '@/composables/useShadcnVariants';
import KinetixDateTimePicker from '../../KinetixDateTimePicker.vue';

defineProps<{ comp: any; value: any }>();

const emit = defineEmits<{ (e: 'update', value: any): void }>();
</script>

<template>
    <!-- shadcn by default, native when ->native() -->
    <KinetixDateTimePicker
        v-if="comp.useCalendar"
        :value="value"
        :disabled="comp.isDisabled"
        :placeholder="comp.placeholder"
        :locale="comp.dateLocale"
        :minute-step="comp.minuteStep"
        :hour12="comp.hour12"
        @update:value="emit('update', $event)"
    />
    <input
        v-else
        :id="comp.name"
        :value="value"
        type="datetime-local"
        :disabled="comp.isDisabled"
        :class="inputClass"
        @input="emit('update', ($event.target as HTMLInputElement).value)"
    />
</template>
