<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { KinetixTableFilter } from '@/types';

const props = defineProps<{
    filter: KinetixTableFilter;
    value: unknown;
}>();

const emit = defineEmits<{
    (e: 'update', value: unknown): void;
}>();

const { t } = useI18n();

const NATIVE_INPUT_CLASS =
    'text-xs p-2 w-full rounded-md border border-border bg-background text-foreground transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

/** Merge a single bound into the current `{ min, max }` value. */
const setPart = (part: 'min' | 'max', partValue: string): void => {
    emit('update', { ...((props.value as object) || {}), [part]: partValue });
};

const bound = (part: 'min' | 'max'): string => {
    const current = (props.value as Record<string, unknown>) || {};

    return current[part] == null ? '' : String(current[part]);
};
</script>

<template>
    <div class="gap-2 flex items-center">
        <input
            type="number"
            :placeholder="t('kinetix.min')"
            :value="bound('min')"
            :class="NATIVE_INPUT_CLASS"
            @input="setPart('min', ($event.target as HTMLInputElement).value)"
        />
        <span class="text-xs text-muted-foreground">–</span>
        <input
            type="number"
            :placeholder="t('kinetix.max')"
            :value="bound('max')"
            :class="NATIVE_INPUT_CLASS"
            @input="setPart('max', ($event.target as HTMLInputElement).value)"
        />
    </div>
</template>
