<script setup lang="ts">
import { SliderRange, SliderRoot, SliderThumb, SliderTrack } from 'reka-ui';
import { computed } from 'vue';

/** Serialized number config (shared with NumberField). */
interface NumberConfig {
    min?: number | null;
    max?: number | null;
    step?: number | null;
}

/**
 * A single-value range slider built on Reka UI's Slider. Emits a plain number;
 * shows the current value beside the track.
 */
const props = withDefaults(
    defineProps<{
        value?: number | string | null;
        config?: NumberConfig | null;
        disabled?: boolean;
    }>(),
    { value: null, config: null, disabled: false },
);

const emit = defineEmits<{ (e: 'update:value', value: number): void }>();

const min = computed(() => props.config?.min ?? 0);
const max = computed(() => props.config?.max ?? 100);
const step = computed(() => props.config?.step ?? 1);

const current = computed<number>(() => {
    const n = Number(props.value);

    return Number.isNaN(n) ? min.value : n;
});

const modelValue = computed<number[]>(() => [current.value]);

function onUpdate(v: number[] | null | undefined): void {
    if (v && v.length) {
        emit('update:value', v[0]);
    }
}
</script>

<template>
    <div class="gap-3 flex items-center">
        <SliderRoot
            :model-value="modelValue"
            :min="min"
            :max="max"
            :step="step"
            :disabled="disabled"
            class="h-5 relative flex w-full touch-none items-center select-none"
            :class="disabled ? 'cursor-not-allowed opacity-50' : ''"
            @update:model-value="onUpdate"
        >
            <SliderTrack class="h-1.5 relative grow rounded-full bg-muted">
                <SliderRange class="absolute h-full rounded-full bg-primary" />
            </SliderTrack>
            <SliderThumb
                class="size-4 shadow-sm block rounded-full border border-primary bg-background transition-[color,box-shadow] outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
            />
        </SliderRoot>
        <span
            class="w-12 text-sm shrink-0 text-right text-foreground tabular-nums"
            >{{ current }}</span
        >
    </div>
</template>
