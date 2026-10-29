<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useKinetixFieldConditions } from '@/composables/useKinetixFieldConditions';
import { schemaHasError } from '@/composables/useKinetixFormErrors';
import {
    requestedIndex,
    writeQueryParam,
} from '@/composables/useKinetixQueryParam';
import {
    gridColumnVars,
    resolveColumns,
} from '@/composables/useKinetixResponsiveGrid';
import {
    holdsTourTarget,
    onTourReveal,
} from '@/composables/useKinetixTourReveal';
import type { KinetixWizardStep } from '@/types/kinetix';
import KinetixFormSchema from './KinetixFormSchema.vue';
import KinetixWizard from './KinetixWizard.vue';

/**
 * Renders a `wizard` form-layout component: maps each `wizard-step` to a
 * KinetixWizard step whose content recurses back into KinetixFormSchema.
 * Advancing is gated on the current step's required fields being filled
 * (client-side); server validation still applies on submit.
 *
 * Validation-aware: steps whose fields hold an error are marked on the
 * indicator, navigable regardless of linear gating, and when errors arrive the
 * wizard jumps to the first offending step (unless the current one has one).
 *
 * URL-aware: when the server sends a `queryParam`, the current step lives in
 * the query string (`?step=billing`), so a reload or a shared link reopens it
 * — but never past a step whose required fields are still empty (a reload of
 * a create form starts over at the first incomplete step). Never inside a
 * modal (`flat`).
 */
const props = defineProps<{
    comp: any;
    values: Record<string, any>;
    errors: Record<string, string>;
    /** Forwarded to nested schemas: chrome-free Sections inside modals. */
    flat?: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:value', name: string, value: any): void;
}>();

const steps = computed<KinetixWizardStep[]>(() =>
    (props.comp.schema ?? []).map((s: any, i: number) => ({
        // The step's key names it in the indicator (`data-kinetix-wizard-step`).
        key: s.urlKey ?? String(i),
        label: s.heading,
        description: s.description,
        icon: s.icon,
        color: s.color,
    })),
);

const current = ref(0);

const errorKeys = computed(() => Object.keys(props.errors ?? {}));

const stepHasError = (index: number): boolean =>
    schemaHasError(props.comp.schema?.[index]?.schema, errorKeys.value);

const errorSteps = computed<number[]>(() =>
    (props.comp.schema ?? [])
        .map((_: any, i: number) => i)
        .filter((i: number) => stepHasError(i)),
);

// Jump to the first step with an error when the error set changes, unless the
// current step already has one (avoids yanking the user during live editing).
watch(
    errorKeys,
    (keys) => {
        if (keys.length === 0 || stepHasError(current.value)) {
            return;
        }

        if (errorSteps.value.length > 0) {
            current.value = errorSteps.value[0];
        }
    },
    { deep: true },
);

const { resolve } = useKinetixFieldConditions();

// Fields that repeat their schema per item: their sub-fields read each item's
// values, not the form's, and are validated on submit.
const ITEM_LISTS = new Set(['repeater', 'table-repeater']);

interface StepRequirement {
    name: string;
    /** Items an item list needs; any other field just needs a value. */
    minItems: number;
}

/**
 * What a step needs filled to move on, as the form shows it NOW: a
 * conditionally hidden field never blocks (the user can't see it) and a
 * `requiredWhen` field blocks only while its condition holds — the same
 * conditions the server applies on submit. An item list counts its own items
 * (`required()`, `minItems()`); the guard doesn't walk into them.
 */
function requirements(nodes: any[]): StepRequirement[] {
    const found: StepRequirement[] = [];
    const walk = (arr: any[]) => {
        for (const n of arr) {
            const effect = resolve(n, props.values);

            if (!effect.visible) {
                continue;
            }

            const isItemList = ITEM_LISTS.has(n.type);

            if (Array.isArray(n.schema) && !isItemList) {
                walk(n.schema);
            }

            const required = Boolean(effect.required ?? n.isRequired);
            const minItems = Math.max(
                required ? 1 : 0,
                isItemList ? (n.minItems ?? 0) : 0,
            );

            if (n.name && minItems > 0) {
                found.push({ name: n.name, minItems });
            }
        }
    };
    walk(nodes);

    return found;
}

function meets(value: any, minItems: number): boolean {
    if (Array.isArray(value)) {
        return value.length >= minItems;
    }

    if (value !== null && typeof value === 'object') {
        return Object.keys(value).length >= minItems;
    }

    return !(value === null || value === undefined || value === '');
}

function beforeNext(index: number): boolean {
    const step = props.comp.schema?.[index];

    if (!step) {
        return true;
    }

    return requirements(step.schema ?? []).every(({ name, minItems }) =>
        meets(props.values[name], minItems),
    );
}

const urlParam = computed<string | null>(() =>
    props.flat ? null : (props.comp.queryParam ?? null),
);

/** The step the URL asks for, held back at the first incomplete step before it. */
function stepFromUrl(): number {
    const requested = requestedIndex(props.comp.schema, urlParam.value);

    for (let index = 0; index < requested; index++) {
        if (!beforeNext(index)) {
            return index;
        }
    }

    return Math.max(0, requested);
}

function writeStep(index: number): void {
    const key = props.comp.schema?.[index]?.urlKey;

    if (urlParam.value && key) {
        writeQueryParam(urlParam.value, key);
    }
}

current.value = stepFromUrl();

// A tour about to highlight a field in a step opens that step — unless a step
// before it still has required fields empty (the same rule the URL follows):
// then the tour points at the step in the indicator instead.
onTourReveal((request) => {
    const index = (props.comp.schema ?? []).findIndex((step: any) =>
        holdsTourTarget(step.schema, request),
    );

    if (index === -1 || index === current.value) {
        return;
    }

    for (let before = 0; before < index; before++) {
        if (!beforeNext(before)) {
            const key = props.comp.schema[index]?.urlKey ?? String(index);
            request.fallback = `[data-kinetix-wizard-step="${CSS.escape(key)}"]`;

            return;
        }
    }

    current.value = index;
});

// Held back from the step the link named: say so in the URL too, so it never
// shows one step while naming another.
if (requestedIndex(props.comp.schema, urlParam.value) > current.value) {
    writeStep(current.value);
}

watch(current, writeStep);
</script>

<template>
    <KinetixWizard
        v-model:step="current"
        :steps="steps"
        :variant="comp.variant || 'stepper'"
        :orientation="comp.orientation || 'horizontal'"
        :step-layout="comp.stepLayout || 'inline'"
        :full-width="comp.fullWidth ?? true"
        :slug="comp.slug"
        :error-steps="errorSteps"
        :before-next="beforeNext"
    >
        <template #default="{ index }">
            <div class="kinetix-grid-host">
                <div
                    class="kinetix-grid gap-4 grid"
                    :style="
                        gridColumnVars(
                            resolveColumns(comp.schema?.[index]?.columns),
                        )
                    "
                >
                    <KinetixFormSchema
                        :schema="comp.schema?.[index]?.schema ?? []"
                        :values="values"
                        :errors="errors"
                        :parent-columns="
                            resolveColumns(comp.schema?.[index]?.columns)
                        "
                        :flat="flat"
                        @update:value="
                            (name, val) => emit('update:value', name, val)
                        "
                    />
                </div>
            </div>
        </template>
    </KinetixWizard>
</template>
