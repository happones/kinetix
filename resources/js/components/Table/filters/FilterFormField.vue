<script setup lang="ts">
import { computed } from 'vue';
import type { KinetixTableFilter } from '@/types/kinetix';
import KinetixFormSchema from '../../KinetixFormSchema.vue';

/**
 * Renders a FormFilter's multi-field schema with the shared KinetixFormSchema,
 * so every form field type (and conditional fields) is available inside a
 * filter. The filter's value is a plain `{ field: value }` object; each field
 * update merges into it and emits the whole object back, which the table sends
 * as the filter's value and the server hands to the FormFilter query callback
 * as `array $data`.
 */
const props = defineProps<{
    filter: KinetixTableFilter;
    value: unknown;
}>();

const emit = defineEmits<{
    (e: 'update', value: unknown): void;
}>();

const values = computed<Record<string, unknown>>(
    () => (props.value as Record<string, unknown>) ?? {},
);

const schema = computed<unknown[]>(
    () => (props.filter.schema as unknown[]) ?? [],
);

const onUpdateValue = (name: string, fieldValue: unknown): void => {
    emit('update', { ...values.value, [name]: fieldValue });
};
</script>

<template>
    <div class="gap-3 grid grid-cols-1">
        <KinetixFormSchema
            :schema="schema"
            :values="values"
            :errors="{}"
            flat
            @update:value="onUpdateValue"
        />
    </div>
</template>
