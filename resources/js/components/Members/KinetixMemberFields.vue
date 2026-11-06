<script setup lang="ts">
import { computed, provide, useId, watch } from 'vue';
import { KINETIX_FIELD_ID_PREFIX } from '@/composables/useKinetixFieldId';
import { focusField } from '@/composables/useKinetixFormErrors';
import type { KinetixMemberFieldsForm } from '@/types/kinetix';
import { preloadKinetixFormFields } from '../Form/kinetixFormFieldLoaders';
import KinetixFormSchema from '../KinetixFormSchema.vue';

/**
 * Renders the custom member fields an app describes (membership.fields)
 * inside the provisioning form and the Edit member modal. Internal: it is a
 * controlled view over a `{ name: value }` record, with no `<form>` of its own
 * so it lives inside the caller's. Each field's server error sits under it
 * (`errors` is keyed by field name).
 */
const props = defineProps<{
    form: KinetixMemberFieldsForm;
    modelValue: Record<string, unknown>;
    errors?: Record<string, string>;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: Record<string, unknown>];
}>();

const schema = computed(() => props.form.schema as any[]);

// Every field type the schema uses starts downloading together, before the
// fields render.
watch(schema, (nodes) => void preloadKinetixFormFields(nodes), {
    immediate: true,
});

const labels = computed<Record<string, string>>(() => {
    const found: Record<string, string> = {};
    const walk = (nodes: any[]): void => {
        for (const node of nodes ?? []) {
            if (node?.name && node.label) {
                found[node.name] = node.label;
            }

            if (Array.isArray(node?.schema)) {
                walk(node.schema);
            }
        }
    };

    walk(schema.value);

    return found;
});

provide(
    'kinetixFieldLabel',
    (name: string): string =>
        labels.value[name] ??
        name.replace(/[_.-]+/g, ' ').replace(/^\w/, (c) => c.toUpperCase()),
);

// Field ids are scoped to this instance, so the provisioning form and the Edit
// dialog (open together) never share an id for a field of the same name.
const idPrefix = `kinetix-member-fields-${useId()}`;

provide(KINETIX_FIELD_ID_PREFIX, idPrefix);

/** Focus a field by name (what an error points at), scrolled into view. */
function focus(name: string | null): void {
    focusField(name ? `${idPrefix}-${name}` : null);
}

defineExpose({ focus });

function onUpdate(name: string, value: unknown): void {
    emit('update:modelValue', { ...props.modelValue, [name]: value });
}
</script>

<template>
    <div
        class="kinetix-form-root gap-4 grid grid-cols-1"
        data-test="member-fields"
    >
        <KinetixFormSchema
            :schema="schema"
            :values="modelValue"
            :errors="errors ?? {}"
            flat
            @update:value="onUpdate"
        />
    </div>
</template>
