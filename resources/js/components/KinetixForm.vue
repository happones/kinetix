<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    firstErroredField,
    focusField,
} from '@/composables/useKinetixFormErrors';
import {
    applyFormChanges,
    useKinetixFormReactivity,
} from '@/composables/useKinetixFormReactivity';
import { useKinetixPrecognition } from '@/composables/useKinetixPrecognition';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';
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
        /** Signed descriptor for server-driven reactivity ($get/$set). */
        recomputeDescriptor?: string | null;
    };
    /**
     * Endpoint for live (Precognition) validation. Falls back to the form's
     * own `validationUrl`; supply it here when the URL is only known client-side
     * (e.g. the same route you submit to).
     */
    validationUrl?: string;
    /**
     * Chrome-free rendering for forms hosted inside a modal or sheet: the
     * panel is already the surface, so Sections render as divided groups
     * instead of nested cards. Pass it whenever the form sits in a
     * KinetixModal/KinetixSheet.
     */
    flat?: boolean;
}>();

const emit = defineEmits<{
    (e: 'submit', values: Record<string, any>): void;
}>();

const { t } = useI18n();
const page = usePage();

const formValues = ref<Record<string, any>>({ ...props.form.data });

// The rendered schema. Starts as the server's initial schema and is replaced
// in place by each server-driven recompute (dependent options, conditional
// visibility that needs the DB, etc.). Kept separate from props so a recompute
// never has to wait for a full Inertia round-trip.
const liveSchema = ref<any[]>(props.form.schema);

// Re-sync the schema if the form is re-rendered from the server (e.g. a failed
// submit re-serializes it), unless the user is mid-recompute.
watch(
    () => props.form.schema,
    (schema) => {
        liveSchema.value = schema;
    },
);

// Find a field's schema node by name (recursing into layout containers), so a
// value change knows whether — and when — to trigger a recompute.
const findField = (name: string, nodes: any[] = liveSchema.value): any => {
    for (const node of nodes) {
        if (node?.name === name) {
            return node;
        }

        const nested = Array.isArray(node?.schema)
            ? findField(name, node.schema)
            : null;

        if (nested) {
            return nested;
        }
    }

    return null;
};

// `live(onBlur: true)` ships `debounce: -1`: its change recomputes when focus
// leaves the field, not on every keystroke.
const pendingOnBlur = new Set<string>();

const onFocusOut = () => {
    for (const name of pendingOnBlur) {
        onFieldChange(true, name, 0);
    }

    pendingOnBlur.clear();
};

const { onFieldChange } = useKinetixFormReactivity({
    descriptor: () => props.form.recomputeDescriptor,
    getValues: () => formValues.value,
    onSchema: (schema) => {
        liveSchema.value = schema as any[];
    },
    onChanges: (changes) => {
        formValues.value = applyFormChanges(formValues.value, changes);
    },
});

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

// Keep values in sync if the form data changes externally — EXCEPT on a
// failed-validation round-trip. There the controller reran and re-serialized
// the form from the ORIGINAL record (edit) or the blank blueprint (create),
// so syncing would overwrite exactly what the user just submitted: a cleared
// required field would silently refill from the record, and a create form
// would wipe itself. When the incoming render carries validation errors, the
// user's values stay; the next error-free render syncs again.
watch(
    () => props.form.data,
    (newData) => {
        if (Object.keys(serverErrors.value).length > 0) {
            return;
        }

        formValues.value = { ...newData };
    },
    { deep: true },
);

// When a fresh set of submit errors arrives, reveal + focus the first one.
// Containers (Tabs/Wizard) independently switch to the panel holding an error
// on the same change; focusField retries across frames until that panel mounts.
// Inertia replaces the error bag wholesale, so watching the reference is enough.
watch(serverErrors, (bag) => {
    const keys = Object.keys(bag);

    if (keys.length === 0) {
        return;
    }

    dismissed.value = {};
    focusField(firstErroredField(props.form.schema, keys));
});

const onUpdateValue = (name: string, value: any) => {
    formValues.value[name] = value;

    // Hide any stale submit error for this field while it's being edited.
    if (serverErrors.value[name] && !dismissed.value[name]) {
        dismissed.value = { ...dismissed.value, [name]: true };
    }

    precognition?.validate(name);

    // A live field drives the server-driven reactivity loop (debounced).
    const field = findField(name);

    if (field?.isLive && field.debounce === -1) {
        pendingOnBlur.add(name);
    } else {
        onFieldChange(
            !!field?.isLive,
            name,
            typeof field?.debounce === 'number' ? field.debounce : undefined,
        );
    }
};

// Dismissals survive the submit: until the response lands, `page.props.errors`
// is still the previous bag, so resetting here would re-flag every field the
// user already fixed. The serverErrors watcher resets them when a fresh bag
// arrives.
const onSubmit = (e: Event) => {
    e.preventDefault();
    emit('submit', formValues.value);
};
</script>

<template>
    <form @submit="onSubmit" @focusout="onFocusOut" class="space-y-6">
        <!-- 1-column root: a field's default span of 1 is the
             full width, and Grid::make(2) opts into columns. -->
        <div class="kinetix-form-root gap-4 grid grid-cols-1">
            <KinetixFormSchema
                :schema="liveSchema"
                :values="formValues"
                :errors="errors"
                :flat="flat"
                @update:value="onUpdateValue"
            />
        </div>

        <!-- Actions Slot -->
        <div class="gap-3 mt-4 flex justify-end">
            <slot :values="formValues" :errors="errors">
                <button type="submit" :class="buttonVariants()">
                    {{ t('kinetix.submit') }}
                </button>
            </slot>
        </div>
    </form>
</template>

<style scoped>
/*
 * The root grid is the outermost container-query host: top-level sections and
 * fields measure their breakpoints against the FORM's width (so a form inside
 * a modal or a narrow pane collapses correctly, viewport regardless).
 */
.kinetix-form-root {
    container-type: inline-size;
}
</style>
