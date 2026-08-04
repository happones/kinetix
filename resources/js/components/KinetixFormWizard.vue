<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { schemaHasError } from '@/composables/useKinetixFormErrors';
import {
    gridColumnVars,
    resolveColumns,
} from '@/composables/useKinetixResponsiveGrid';
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
 */
const props = defineProps<{
    comp: any;
    values: Record<string, any>;
    errors: Record<string, string>;
}>();

const emit = defineEmits<{
    (e: 'update:value', name: string, value: any): void;
}>();

const steps = computed<KinetixWizardStep[]>(() =>
    (props.comp.schema ?? []).map((s: any, i: number) => ({
        key: String(i),
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

const isFilled = (v: any): boolean =>
    !(
        v === null ||
        v === undefined ||
        v === '' ||
        (Array.isArray(v) && v.length === 0)
    );

function requiredNames(nodes: any[]): string[] {
    const names: string[] = [];
    const walk = (arr: any[]) => {
        for (const n of arr) {
            if (Array.isArray(n.schema)) {
                walk(n.schema);
            }

            if (n.name && n.isRequired) {
                names.push(n.name);
            }
        }
    };
    walk(nodes);

    return names;
}

function beforeNext(index: number): boolean {
    const step = props.comp.schema?.[index];

    if (!step) {
        return true;
    }

    return requiredNames(step.schema ?? []).every((name) =>
        isFilled(props.values[name]),
    );
}
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
                        @update:value="
                            (name, val) => emit('update:value', name, val)
                        "
                    />
                </div>
            </div>
        </template>
    </KinetixWizard>
</template>
