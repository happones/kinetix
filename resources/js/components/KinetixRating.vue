<script setup lang="ts">
import { Star, StarHalf } from '@lucide/vue';
import { computed, ref } from 'vue';

/** Serialized rating config. */
interface RatingConfig {
    max?: number | null;
    allowHalf?: boolean | null;
}

/**
 * Star rating field. Stores a number from 0..max (halves when allowHalf). Click
 * the same value again to clear it.
 */
const props = withDefaults(
    defineProps<{
        value?: number | string | null;
        config?: RatingConfig | null;
        disabled?: boolean;
    }>(),
    { value: null, config: null, disabled: false },
);

const emit = defineEmits<{ (e: 'update:value', value: number): void }>();

const max = computed(() => props.config?.max ?? 5);
const allowHalf = computed(() => props.config?.allowHalf ?? false);
const current = computed(() => Number(props.value) || 0);

const hover = ref(0);
const shown = computed(() => (hover.value > 0 ? hover.value : current.value));

/** Star fill state for a 1-based index given the shown value. */
function fill(index: number): 'full' | 'half' | 'empty' {
    if (shown.value >= index) {
        return 'full';
    }

    if (allowHalf.value && shown.value >= index - 0.5) {
        return 'half';
    }

    return 'empty';
}

function valueAt(index: number, event: MouseEvent): number {
    if (!allowHalf.value) {
        return index;
    }

    const el = event.currentTarget as HTMLElement;
    const { left, width } = el.getBoundingClientRect();

    return event.clientX - left < width / 2 ? index - 0.5 : index;
}

function onMove(index: number, event: MouseEvent): void {
    if (!props.disabled) {
        hover.value = valueAt(index, event);
    }
}

function onClick(index: number, event: MouseEvent): void {
    if (props.disabled) {
        return;
    }

    const next = valueAt(index, event);
    // Click the current value again to clear.
    emit('update:value', next === current.value ? 0 : next);
}
</script>

<template>
    <div
        class="gap-0.5 inline-flex items-center"
        :class="disabled ? 'cursor-not-allowed opacity-50' : ''"
        @mouseleave="hover = 0"
    >
        <button
            v-for="i in max"
            :key="i"
            type="button"
            :disabled="disabled"
            class="text-amber-400 relative transition-transform hover:scale-110 disabled:pointer-events-none"
            :aria-label="`${i}`"
            @mousemove="onMove(i, $event)"
            @click="onClick(i, $event)"
        >
            <Star
                v-if="fill(i) !== 'full'"
                class="size-6 text-muted-foreground/40"
            />
            <Star
                v-if="fill(i) === 'full'"
                class="size-6 fill-amber-400 text-amber-400"
            />
            <StarHalf
                v-else-if="fill(i) === 'half'"
                class="inset-0 size-6 fill-amber-400 text-amber-400 absolute"
            />
        </button>
    </div>
</template>
