<script setup lang="ts">
import { inputClass } from '@/composables/useShadcnVariants';
import KinetixDatePicker from '../../KinetixDatePicker.vue';

defineProps<{ comp: any; value: any }>();

const emit = defineEmits<{ (e: 'update', value: any): void }>();
</script>

<template>
    <!-- shadcn calendar by default, native when ->native() -->
    <KinetixDatePicker
        v-if="comp.useCalendar"
        :value="value"
        :disabled="comp.isDisabled"
        :placeholder="comp.placeholder"
        :locale="comp.dateLocale"
        @update:value="emit('update', $event)"
    />
    <input
        v-else
        :id="comp.name"
        :value="value"
        type="date"
        :disabled="comp.isDisabled"
        :class="inputClass"
        @input="emit('update', ($event.target as HTMLInputElement).value)"
    />
</template>
