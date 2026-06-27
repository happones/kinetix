<script setup lang="ts">
import { Minus, Plus } from '@lucide/vue';
import {
    NumberFieldDecrement,
    NumberFieldIncrement,
    NumberFieldInput,
    NumberFieldRoot,
} from 'reka-ui';
import { computed } from 'vue';

/** Serialized number config shared by NumberField + NumberInputColumn. */
interface NumberConfig {
    min?: number | null;
    max?: number | null;
    step?: number | null;
    format?: 'decimal' | 'percent' | 'currency' | null;
    currency?: string | null;
    decimals?: { min: number; max: number } | null;
    locale?: string | null;
}

/**
 * Numeric input with increment/decrement steppers, built on Reka UI's
 * NumberField. Honors min/max/step and Intl formatting (decimal/percent/
 * currency). Emits a number (or null when cleared).
 */
const props = withDefaults(
    defineProps<{
        value?: number | string | null;
        config?: NumberConfig | null;
        disabled?: boolean;
        placeholder?: string | null;
        /** Compact styling for inline table cells. */
        compact?: boolean;
    }>(),
    {
        value: null,
        config: null,
        disabled: false,
        placeholder: null,
        compact: false,
    },
);

const emit = defineEmits<{ (e: 'update:value', value: number | null): void }>();

const modelValue = computed<number | undefined>(() => {
    if (
        props.value === null ||
        props.value === undefined ||
        props.value === ''
    ) {
        return undefined;
    }

    const n = Number(props.value);

    return Number.isNaN(n) ? undefined : n;
});

const formatOptions = computed<Intl.NumberFormatOptions | undefined>(() => {
    const c = props.config;

    if (!c) {
        return undefined;
    }

    const opts: Intl.NumberFormatOptions = {};

    if (c.format === 'percent') {
        opts.style = 'percent';
    } else if (c.format === 'currency' && c.currency) {
        opts.style = 'currency';
        opts.currency = c.currency;
    }

    if (c.decimals) {
        opts.minimumFractionDigits = c.decimals.min;
        opts.maximumFractionDigits = c.decimals.max;
    }

    return Object.keys(opts).length ? opts : undefined;
});

function onUpdate(v: number | undefined): void {
    emit('update:value', v === undefined || Number.isNaN(v) ? null : v);
}
</script>

<template>
    <NumberFieldRoot
        :model-value="modelValue"
        :min="config?.min ?? undefined"
        :max="config?.max ?? undefined"
        :step="config?.step ?? 1"
        :disabled="disabled"
        :format-options="formatOptions"
        :locale="config?.locale ?? undefined"
        class="shadow-xs inline-flex items-center rounded-md border border-input bg-transparent transition-[color,box-shadow] focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50 dark:bg-input/30"
        :class="[
            compact ? 'h-8 w-32 text-xs' : 'h-9 text-sm w-full',
            disabled ? 'cursor-not-allowed opacity-50' : '',
        ]"
        @update:model-value="onUpdate"
    >
        <NumberFieldDecrement
            class="px-2 flex h-full items-center justify-center text-muted-foreground transition-colors hover:text-foreground disabled:opacity-40"
        >
            <Minus :class="compact ? 'size-3' : 'size-4'" />
        </NumberFieldDecrement>
        <NumberFieldInput
            :placeholder="placeholder ?? ''"
            class="min-w-0 flex-1 bg-transparent text-center text-foreground tabular-nums outline-none"
        />
        <NumberFieldIncrement
            class="px-2 flex h-full items-center justify-center text-muted-foreground transition-colors hover:text-foreground disabled:opacity-40"
        >
            <Plus :class="compact ? 'size-3' : 'size-4'" />
        </NumberFieldIncrement>
    </NumberFieldRoot>
</template>
