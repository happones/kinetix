<script setup lang="ts">
import { RadioGroupIndicator, RadioGroupItem, RadioGroupRoot } from 'reka-ui';
import { computed } from 'vue';
import { kinetixOptionList } from '@/composables/kinetixOptions';
import type { KinetixOptions } from '@/composables/kinetixOptions';

const props = withDefaults(
    defineProps<{
        value?: string | number | null;
        /** value → label, or a list when the order matters. */
        options?: KinetixOptions;
        disabled?: boolean;
        inline?: boolean;
    }>(),
    { value: null, options: () => ({}), disabled: false, inline: false },
);

const items = computed(() => kinetixOptionList(props.options));

const emit = defineEmits<{
    (e: 'update:value', value: string): void;
}>();
</script>

<template>
    <RadioGroupRoot
        :model-value="
            value !== null && value !== undefined ? String(value) : ''
        "
        :disabled="disabled"
        :class="
            inline ? 'gap-4 flex flex-wrap items-center' : 'gap-2 flex flex-col'
        "
        @update:model-value="emit('update:value', $event as string)"
    >
        <label
            v-for="option in items"
            :key="option.value"
            class="gap-2 text-sm flex items-center text-foreground"
            :class="
                disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer'
            "
        >
            <RadioGroupItem
                :value="option.value"
                class="size-4 shadow-xs flex aspect-square shrink-0 items-center justify-center rounded-full border border-input text-primary transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 data-[state=checked]:border-primary dark:bg-input/30 dark:aria-invalid:ring-destructive/40"
            >
                <RadioGroupIndicator class="flex items-center justify-center">
                    <span class="h-2 w-2 rounded-full bg-primary" />
                </RadioGroupIndicator>
            </RadioGroupItem>
            {{ option.label }}
        </label>
    </RadioGroupRoot>
</template>
