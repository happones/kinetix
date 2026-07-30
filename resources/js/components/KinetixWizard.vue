<script setup lang="ts">
import { computed, ref, useSlots, watch } from 'vue';
import type { Component } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixWizard } from '@/composables/useKinetixWizard';
import { useKinetixWizardStatus } from '@/composables/useKinetixWizardStatus';
import { buttonVariants } from '@/composables/useShadcnVariants';
import type {
    KinetixWizardStep,
    KinetixWizardStepLayout,
    KinetixWizardVariant,
} from '@/types/kinetix';
import { cn } from './primitives/cn';
import WizardDefaultIndicator from './Wizard/WizardDefaultIndicator.vue';
import WizardPanelsIndicator from './Wizard/WizardPanelsIndicator.vue';
import WizardSimpleIndicator from './Wizard/WizardSimpleIndicator.vue';
import WizardStepperIndicator from './Wizard/WizardStepperIndicator.vue';
import WizardVerticalIndicator from './Wizard/WizardVerticalIndicator.vue';

/**
 * A standalone, page-usable multi-step wizard. Step *content* is provided via
 * slots — either a slot named after each step's key, or the scoped default slot
 * (`{ step, index, stepKey }`) — so it can hold forms, choices, anything.
 *
 * Advancing runs the optional `beforeNext` guard (return false / reject to
 * block), enabling per-step validation. When a `slug` is set, finishing marks
 * the wizard complete server-side, releasing the `kinetix.wizard:<slug>` gate.
 */
const props = withDefaults(
    defineProps<{
        steps: KinetixWizardStep[];
        variant?: KinetixWizardVariant;
        /** Indicator orientation for the `stepper` / `vertical` variants. */
        orientation?: 'horizontal' | 'vertical';
        /**
         * How each step's indicator + label are arranged (`stepper` variant,
         * horizontal only): `inline` (default) side by side, `stacked`
         * indicator on top, or `tooltip` indicator only + label on hover/focus.
         */
        stepLayout?: KinetixWizardStepLayout;
        /** Gating slug — completion is persisted on finish. */
        slug?: string | null;
        /** Controlled current step index (v-model:step). */
        step?: number;
        /** Only allow forward navigation through completed steps. */
        linear?: boolean;
        /**
         * Stretch the (horizontal) indicator to fill the container width,
         * distributing steps evenly. Set `false` for a compact, centered
         * indicator sized to its content. No effect on vertical layouts.
         */
        fullWidth?: boolean;
        /** Guard run before advancing/finishing a step. */
        beforeNext?: (fromIndex: number) => boolean | Promise<boolean>;
        /**
         * Indexes of steps that currently hold a validation error. Their
         * indicator is marked destructive and they stay navigable even under
         * `linear` gating, so a user can jump straight to the failing step.
         */
        errorSteps?: number[];
    }>(),
    {
        variant: 'stepper',
        orientation: 'horizontal',
        stepLayout: 'inline',
        slug: null,
        step: undefined,
        linear: true,
        fullWidth: true,
        errorSteps: () => [],
    },
);

const emit = defineEmits<{
    (e: 'update:step', index: number): void;
    (e: 'step-change', index: number): void;
    (e: 'finish'): void;
}>();

const { t } = useI18n();
const slots = useSlots();
const wizard = useKinetixWizard();

const internal = ref(props.step ?? 0);
const maxReached = ref(internal.value);
const busy = ref(false);

watch(
    () => props.step,
    (v) => {
        if (typeof v === 'number' && v !== internal.value) {
            internal.value = v;
        }
    },
);

const current = computed(() => internal.value);
const total = computed(() => props.steps.length);
const isFirst = computed(() => current.value === 0);
const isLast = computed(() => current.value === total.value - 1);
const currentStep = computed(() => props.steps[current.value]);

// Per-step status derivations shared with the indicator components.
const { hasError, stepKey } = useKinetixWizardStatus({
    current: () => current.value,
    maxReached: () => maxReached.value,
    errorSteps: () => props.errorSteps,
    linear: () => props.linear,
});

const currentKey = computed(() => stepKey(currentStep.value, current.value));

// variant → indicator component. Resolving by lookup keeps the container free
// of the ~6-branch indicator chain; `default`/`gradient` and any unknown
// variant fall back to the circles-and-connectors indicator.
const INDICATORS: Record<string, Component> = {
    stepper: WizardStepperIndicator,
    simple: WizardSimpleIndicator,
    panels: WizardPanelsIndicator,
    vertical: WizardVerticalIndicator,
    default: WizardDefaultIndicator,
    gradient: WizardDefaultIndicator,
};

const indicatorComponent = computed<Component>(
    () => INDICATORS[props.variant] ?? WizardDefaultIndicator,
);

const indicatorProps = computed(() => ({
    steps: props.steps,
    current: current.value,
    maxReached: maxReached.value,
    errorSteps: props.errorSteps,
    linear: props.linear,
    fullWidth: props.fullWidth,
    stepLayout: props.stepLayout,
    orientation: props.orientation,
    gradient: props.variant === 'gradient',
}));

function setStep(index: number): void {
    internal.value = index;
    maxReached.value = Math.max(maxReached.value, index);
    emit('update:step', index);
    emit('step-change', index);
}

async function runGuard(): Promise<boolean> {
    if (!props.beforeNext) {
        return true;
    }

    busy.value = true;

    try {
        return (await props.beforeNext(current.value)) !== false;
    } finally {
        busy.value = false;
    }
}

async function next(): Promise<void> {
    if (busy.value) {
        return;
    }

    if (!(await runGuard())) {
        return;
    }

    if (isLast.value) {
        await finish();

        return;
    }

    setStep(current.value + 1);
}

function prev(): void {
    if (!isFirst.value) {
        setStep(current.value - 1);
    }
}

async function finish(): Promise<void> {
    if (props.slug) {
        busy.value = true;

        try {
            await wizard.complete(props.slug);
        } finally {
            busy.value = false;
        }
    }

    emit('finish');
}

/** Jump to a step from the indicator (only backwards / reached steps when linear). */
function goTo(index: number): void {
    if (index === current.value) {
        return;
    }

    // Errored steps are always reachable, even ahead under linear gating.
    if (props.linear && index > maxReached.value && !hasError(index)) {
        return;
    }

    setStep(index);
}
</script>

<template>
    <div
        :class="
            variant === 'vertical' ||
            (variant === 'stepper' && orientation === 'vertical')
                ? 'gap-6 md:flex-row flex flex-col'
                : ''
        "
    >
        <!-- ===== Indicator (variant → component map) ===== -->
        <component
            :is="indicatorComponent"
            v-bind="indicatorProps"
            @goto="goTo"
        />

        <!-- ===== Content + actions ===== -->
        <div class="min-w-0 flex-1">
            <div class="min-h-[4rem]">
                <component
                    :is="
                        () =>
                            slots[currentKey]!({
                                step: currentStep,
                                index: current,
                            })
                    "
                    v-if="slots[currentKey]"
                />
                <slot
                    v-else
                    :step="currentStep"
                    :index="current"
                    :step-key="currentKey"
                />
            </div>

            <slot
                name="actions"
                :next="next"
                :prev="prev"
                :finish="finish"
                :is-first="isFirst"
                :is-last="isLast"
                :busy="busy"
                :current="current"
            >
                <div
                    class="mt-6 pt-4 flex items-center justify-between border-t border-border"
                >
                    <button
                        type="button"
                        :class="
                            buttonVariants({ variant: 'outline', size: 'sm' })
                        "
                        :disabled="isFirst || busy"
                        @click="prev"
                    >
                        {{ t('kinetix.wizard_back') }}
                    </button>
                    <button
                        type="button"
                        :class="cn(buttonVariants({ size: 'sm' }))"
                        :disabled="busy"
                        @click="next"
                    >
                        {{
                            isLast
                                ? t('kinetix.wizard_finish')
                                : t('kinetix.wizard_next')
                        }}
                    </button>
                </div>
            </slot>
        </div>
    </div>
</template>
