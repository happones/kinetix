<script setup lang="ts">
import { PinInputInput, PinInputRoot } from 'reka-ui';
import { computed } from 'vue';

/** Serialized pin config. */
interface PinConfig {
    length?: number | null;
    mask?: boolean | null;
    otp?: boolean | null;
    type?: 'text' | 'number' | null;
}

/**
 * Segmented PIN / OTP input built on Reka UI's PinInput. Stores the joined value
 * as a string; emits it on every change.
 */
const props = withDefaults(
    defineProps<{
        value?: string | null;
        config?: PinConfig | null;
        disabled?: boolean;
    }>(),
    { value: null, config: null, disabled: false },
);

const emit = defineEmits<{ (e: 'update:value', value: string): void }>();

const length = computed(() => props.config?.length ?? 6);
const model = computed<string[]>(() =>
    (props.value ?? '').split('').slice(0, length.value),
);

function onUpdate(values: string[]): void {
    emit('update:value', values.join(''));
}
</script>

<template>
    <PinInputRoot
        :model-value="model"
        :mask="config?.mask ?? false"
        :otp="config?.otp ?? false"
        :type="config?.type ?? 'text'"
        :disabled="disabled"
        class="gap-2 flex items-center"
        :class="disabled ? 'cursor-not-allowed opacity-50' : ''"
        @update:model-value="onUpdate"
    >
        <PinInputInput
            v-for="i in length"
            :key="i"
            :index="i - 1"
            class="size-10 text-sm shadow-xs rounded-md border border-input bg-transparent text-center text-foreground transition-[color,box-shadow] outline-none focus:border-ring focus:ring-[3px] focus:ring-ring/50 dark:bg-input/30"
        />
    </PinInputRoot>
</template>
