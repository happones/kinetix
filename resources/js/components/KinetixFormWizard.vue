<script setup lang="ts">
import { computed } from 'vue';
import type { KinetixWizardStep } from '@/types';
import KinetixFormSchema from './KinetixFormSchema.vue';
import KinetixWizard from './KinetixWizard.vue';

/**
 * Renders a `wizard` form-layout component: maps each `wizard-step` to a
 * KinetixWizard step whose content recurses back into KinetixFormSchema.
 * Advancing is gated on the current step's required fields being filled
 * (server validation still applies on submit).
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
        :steps="steps"
        :variant="comp.variant || 'stepper'"
        :orientation="comp.orientation || 'horizontal'"
        :step-layout="comp.stepLayout || 'inline'"
        :full-width="comp.fullWidth ?? true"
        :slug="comp.slug"
        :before-next="beforeNext"
    >
        <template #default="{ index }">
            <div
                class="gap-4 grid"
                :style="{
                    gridTemplateColumns: `repeat(${comp.schema?.[index]?.columns || 12}, minmax(0, 1fr))`,
                }"
            >
                <KinetixFormSchema
                    :schema="comp.schema?.[index]?.schema ?? []"
                    :values="values"
                    :errors="errors"
                    @update:value="
                        (name, val) => emit('update:value', name, val)
                    "
                />
            </div>
        </template>
    </KinetixWizard>
</template>
