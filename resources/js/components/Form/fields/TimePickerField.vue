<script setup lang="ts">
import { inputClass } from '@/composables/useKinetixShadcnVariants';
import KinetixTimePicker from '../../KinetixTimePicker.vue';

defineProps<{ comp: any; value: any }>();

const emit = defineEmits<{ (e: 'update', value: any): void }>();
</script>

<template>
    <!-- shadcn columns by default, native when ->native() -->
    <KinetixTimePicker
        v-if="comp.useCalendar"
        :value="value"
        :disabled="comp.isDisabled"
        :minute-step="comp.minuteStep"
        :hour12="comp.hour12"
        :confirm="comp.confirm"
        :timezone="comp.timezone"
        @update:value="emit('update', $event)"
    />
    <input
        v-else
        :id="comp.name"
        :value="value"
        type="time"
        :disabled="comp.isDisabled"
        :class="inputClass"
        @input="emit('update', ($event.target as HTMLInputElement).value)"
    />
</template>
