<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import {
    firstErroredField,
    focusField,
} from '@/composables/useKinetixFormErrors';
import { useKinetixPrecognition } from '@/composables/useKinetixPrecognition';
import { buttonVariants } from '@/composables/useShadcnVariants';
import KinetixFormSchema from './KinetixFormSchema.vue';

const props = defineProps<{
    form: {
        schema: any[];
        data: Record<string, any>;
        rules: Record<string, any>;
        operation: string;
        precognitive?: boolean;
        validationUrl?: string | null;
        validationMethod?: string;
    };
    /**
     * Endpoint for live (Precognition) validation. Falls back to the form's
     * own `validationUrl`; supply it here when the URL is only known client-side
     * (e.g. the same route you submit to).
     */
    validationUrl?: string;
}>();

const emit = defineEmits<{
    (e: 'submit', values: Record<string, any>): void;
}>();

const page = usePage();

const formValues = ref<Record<string, any>>({ ...props.form.data });

// Server (Inertia) validation errors from the last submit. Fields the user has
// since edited are dismissed so a stale message doesn't linger under an input
// they're actively fixing.
const serverErrors = computed(
    () => (page.props.errors ?? {}) as Record<string, string>,
);
const dismissed = ref<Record<string, true>>({});

// --- Live validation (Precognition), opt-in via Form::precognitive() ---------
const precognitionUrl =
    props.validationUrl ?? props.form.validationUrl ?? undefined;
const precognition =
    props.form.precognitive && precognitionUrl
        ? useKinetixPrecognition({
              url: precognitionUrl,
              method: props.form.validationMethod ?? 'post',
              getData: () => formValues.value,
          })
        : null;

// Merged, deduped error bag rendered by the schema. Live (Precognition) errors
// win over last-submit errors for the same field.
const errors = computed<Record<string, string>>(() => {
    const merged: Record<string, string> = {};

    for (const [name, message] of Object.entries(serverErrors.value)) {
        if (!dismissed.value[name]) {
            merged[name] = message;
        }
    }

    if (precognition) {
        Object.assign(merged, precognition.errors.value);
    }

    return merged;
});

// Keep values in sync if the form data changes externally.
watch(
    () => props.form.data,
    (newData) => {
        formValues.value = { ...newData };
    },
    { deep: true },
);

// When a fresh set of submit errors arrives, reveal + focus the first one.
// Containers (Tabs/Wizard) independently switch to the panel holding an error
// on the same change; focusField retries across frames until that panel mounts.
watch(
    serverErrors,
    (bag) => {
        const keys = Object.keys(bag);

        if (keys.length === 0) {
            return;
        }

        dismissed.value = {};
        focusField(firstErroredField(props.form.schema, keys));
    },
    { deep: true },
);

const onUpdateValue = (name: string, value: any) => {
    formValues.value[name] = value;

    // Hide any stale submit error for this field while it's being edited.
    if (serverErrors.value[name] && !dismissed.value[name]) {
        dismissed.value = { ...dismissed.value, [name]: true };
    }

    precognition?.validate(name);
};

const onSubmit = (e: Event) => {
    e.preventDefault();
    dismissed.value = {};
    emit('submit', formValues.value);
};
</script>

<template>
    <form @submit="onSubmit" class="space-y-6">
        <div class="gap-4 grid grid-cols-12">
            <KinetixFormSchema
                :schema="form.schema"
                :values="formValues"
                :errors="errors"
                @update:value="onUpdateValue"
            />
        </div>

        <!-- Actions Slot -->
        <div class="gap-3 mt-4 flex justify-end">
            <slot :values="formValues" :errors="errors">
                <button type="submit" :class="buttonVariants()">Submit</button>
            </slot>
        </div>
    </form>
</template>
