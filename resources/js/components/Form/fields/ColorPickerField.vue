<script setup lang="ts">
import { inputClass } from '@/composables/useKinetixShadcnVariants';
import { cn } from '../../primitives/cn';

defineProps<{ comp: any; value: any }>();

const emit = defineEmits<{ (e: 'update', value: any): void }>();
</script>

<template>
    <div class="gap-2 flex items-center">
        <input
            type="color"
            :value="value || '#000000'"
            :disabled="comp.isDisabled"
            class="h-9 w-12 p-1 shrink-0 cursor-pointer rounded-md border border-input bg-background disabled:cursor-not-allowed disabled:opacity-50"
            @input="emit('update', ($event.target as HTMLInputElement).value)"
        />
        <input
            :id="comp.name"
            :value="value"
            type="text"
            :placeholder="comp.placeholder || '#000000'"
            :disabled="comp.isDisabled"
            :class="cn(inputClass, 'font-mono')"
            @input="emit('update', ($event.target as HTMLInputElement).value)"
        />
    </div>
</template>
