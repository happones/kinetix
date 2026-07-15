<script setup lang="ts">
import { Check } from '@lucide/vue';
import {
    StepperDescription,
    StepperIndicator,
    StepperItem,
    StepperRoot,
    StepperSeparator,
    StepperTitle,
    StepperTrigger,
    TooltipArrow,
    TooltipContent,
    TooltipPortal,
    TooltipProvider,
    TooltipRoot,
    TooltipTrigger,
} from 'reka-ui';
import { computed, ref, useSlots, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { resolveIcon } from '@/composables/useKinetixIcons';
import { useKinetixWizard } from '@/composables/useKinetixWizard';
import { buttonVariants } from '@/composables/useShadcnVariants';
import { statusButtonClass } from '@/composables/useStatusColor';
import type {
    KinetixWizardStep,
    KinetixWizardStepLayout,
    KinetixWizardVariant,
} from '@/types';
import { cn } from './primitives/cn';

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
const percent = computed(() =>
    total.value <= 1
        ? 100
        : Math.round((current.value / (total.value - 1)) * 100),
);

const stepKey = (step: KinetixWizardStep, index: number): string =>
    step.key ?? String(index);
const currentKey = computed(() => stepKey(currentStep.value, current.value));

function statusOf(index: number): 'complete' | 'active' | 'upcoming' {
    if (index < current.value) {
        return 'complete';
    }

    return index === current.value ? 'active' : 'upcoming';
}

/** Whether a step currently holds a validation error. */
function hasError(index: number): boolean {
    return props.errorSteps.includes(index);
}

/**
 * A step's indicator is disabled under linear gating once it's past the
 * furthest-reached step — except errored steps, which stay reachable so the
 * user can jump to a failed step surfaced after submit.
 */
function stepDisabled(index: number): boolean {
    return props.linear && index > maxReached.value && !hasError(index);
}

/**
 * The `stepper` indicator's fill: neutral while upcoming, otherwise the
 * step's own `color` (Gate-independent status token) or primary by default.
 * Computed in script (not via `group-data-[state=]:` CSS selectors) since a
 * per-step color can't be expressed as a static Tailwind class.
 */
function indicatorClass(step: KinetixWizardStep, index: number): string {
    if (hasError(index)) {
        return 'bg-destructive text-white ring-2 ring-destructive/30';
    }

    if (statusOf(index) === 'upcoming') {
        return 'border border-border bg-card text-muted-foreground';
    }

    return step.color
        ? statusButtonClass(step.color)
        : 'bg-primary text-primary-foreground';
}

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
        <!-- ===== Indicator ===== -->
        <!-- stepper: the official reka/shadcn stepper (horizontal or vertical) -->
        <!-- Horizontal is wrapped in its own scroll container: with many steps
             (5-6+) or long labels, the strip can need more width than the
             viewport has — it scrolls internally instead of breaking the
             page's layout. Vertical is a fixed-width column, never needs it. -->
        <div
            v-if="variant === 'stepper' && orientation === 'horizontal'"
            class="overflow-x-auto"
        >
            <TooltipProvider :disable-hoverable-content="true">
                <StepperRoot
                    :model-value="current + 1"
                    orientation="horizontal"
                    class="flex"
                    :class="
                        fullWidth
                            ? 'mb-6 gap-2 w-full items-center'
                            : 'mb-6 gap-2 mx-auto w-fit items-center'
                    "
                >
                    <StepperItem
                        v-for="(s, i) in steps"
                        :key="stepKey(s, i)"
                        :step="i + 1"
                        :disabled="stepDisabled(i)"
                        class="group min-w-0 flex disabled:pointer-events-none disabled:opacity-50"
                        :class="[
                            fullWidth ? 'flex-1 last:flex-none' : 'shrink-0',
                            stepLayout === 'stacked'
                                ? 'gap-2 items-start'
                                : 'gap-2 items-center',
                        ]"
                    >
                        <!-- inline (default): indicator + label side by side, label hidden below sm: -->
                        <StepperTrigger
                            v-if="stepLayout === 'inline'"
                            as-child
                            class="gap-3 min-w-0 flex items-center"
                            @click="goTo(i)"
                        >
                            <button
                                type="button"
                                class="gap-3 min-w-0 flex items-center"
                            >
                                <StepperIndicator
                                    class="size-9 text-sm font-semibold flex shrink-0 items-center justify-center rounded-full transition-colors"
                                    :class="indicatorClass(s, i)"
                                >
                                    <Check
                                        v-if="statusOf(i) === 'complete'"
                                        class="size-4"
                                    />
                                    <component
                                        :is="resolveIcon(s.icon)"
                                        v-else-if="resolveIcon(s.icon)"
                                        class="size-4"
                                    />
                                    <template v-else>{{ i + 1 }}</template>
                                </StepperIndicator>
                                <span class="sm:block min-w-0 hidden text-left">
                                    <StepperTitle
                                        class="text-sm font-medium block truncate text-foreground"
                                        >{{ s.label }}</StepperTitle
                                    >
                                    <StepperDescription
                                        v-if="s.description"
                                        class="text-xs block truncate text-muted-foreground"
                                        >{{ s.description }}</StepperDescription
                                    >
                                </span>
                            </button>
                        </StepperTrigger>

                        <!-- stacked: indicator on top, label/description centered below, always visible -->
                        <StepperTrigger
                            v-else-if="stepLayout === 'stacked'"
                            as-child
                            class="gap-1.5 min-w-0 flex flex-col items-center"
                            @click="goTo(i)"
                        >
                            <button
                                type="button"
                                class="gap-1.5 min-w-0 flex flex-col items-center"
                            >
                                <StepperIndicator
                                    class="size-9 text-sm font-semibold flex shrink-0 items-center justify-center rounded-full transition-colors"
                                    :class="indicatorClass(s, i)"
                                >
                                    <Check
                                        v-if="statusOf(i) === 'complete'"
                                        class="size-4"
                                    />
                                    <component
                                        :is="resolveIcon(s.icon)"
                                        v-else-if="resolveIcon(s.icon)"
                                        class="size-4"
                                    />
                                    <template v-else>{{ i + 1 }}</template>
                                </StepperIndicator>
                                <span class="min-w-0 max-w-full text-center">
                                    <StepperTitle
                                        class="text-xs font-medium block truncate text-foreground"
                                        >{{ s.label }}</StepperTitle
                                    >
                                    <StepperDescription
                                        v-if="s.description"
                                        class="block truncate text-[11px] text-muted-foreground"
                                        >{{ s.description }}</StepperDescription
                                    >
                                </span>
                            </button>
                        </StepperTrigger>

                        <!-- tooltip: indicator only, label/description on hover/focus -->
                        <TooltipRoot v-else>
                            <TooltipTrigger as-child>
                                <StepperTrigger as-child @click="goTo(i)">
                                    <button
                                        type="button"
                                        :aria-label="
                                            s.description
                                                ? `${s.label}: ${s.description}`
                                                : s.label
                                        "
                                    >
                                        <StepperIndicator
                                            class="size-9 text-sm font-semibold flex shrink-0 items-center justify-center rounded-full transition-colors"
                                            :class="indicatorClass(s, i)"
                                        >
                                            <Check
                                                v-if="
                                                    statusOf(i) === 'complete'
                                                "
                                                class="size-4"
                                            />
                                            <component
                                                :is="resolveIcon(s.icon)"
                                                v-else-if="resolveIcon(s.icon)"
                                                class="size-4"
                                            />
                                            <template v-else>{{
                                                i + 1
                                            }}</template>
                                        </StepperIndicator>
                                    </button>
                                </StepperTrigger>
                            </TooltipTrigger>
                            <TooltipPortal>
                                <TooltipContent
                                    :side-offset="6"
                                    class="px-3 py-1.5 text-sm shadow-md data-[state=closed]:animate-out data-[state=open]:animate-in data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 z-50 max-w-[16rem] rounded-md border border-border bg-popover text-popover-foreground"
                                >
                                    <p class="font-medium">{{ s.label }}</p>
                                    <p
                                        v-if="s.description"
                                        class="text-muted-foreground"
                                    >
                                        {{ s.description }}
                                    </p>
                                    <TooltipArrow class="fill-popover" />
                                </TooltipContent>
                            </TooltipPortal>
                        </TooltipRoot>

                        <StepperSeparator
                            v-if="i < steps.length - 1"
                            class="h-0.5 shrink-0 rounded-full bg-border group-data-[state=completed]:bg-primary"
                            :class="[
                                fullWidth ? 'flex-1' : 'w-10',
                                stepLayout === 'stacked' ? 'mt-[18px]' : '',
                            ]"
                        />
                    </StepperItem>
                </StepperRoot>
            </TooltipProvider>
        </div>

        <!-- vertical: fixed-width column, wraps text normally within its own width -->
        <StepperRoot
            v-else-if="variant === 'stepper'"
            :model-value="current + 1"
            orientation="vertical"
            class="gap-0 md:w-64 flex shrink-0 flex-col"
        >
            <StepperItem
                v-for="(s, i) in steps"
                :key="stepKey(s, i)"
                :step="i + 1"
                :disabled="stepDisabled(i)"
                class="group gap-3 flex disabled:pointer-events-none disabled:opacity-50"
            >
                <!-- indicator column, with the connector below it -->
                <div class="flex flex-col items-center self-stretch">
                    <StepperTrigger as-child @click="goTo(i)">
                        <button type="button">
                            <StepperIndicator
                                class="size-9 text-sm font-semibold flex shrink-0 items-center justify-center rounded-full transition-colors"
                                :class="indicatorClass(s, i)"
                            >
                                <Check
                                    v-if="statusOf(i) === 'complete'"
                                    class="size-4"
                                />
                                <component
                                    :is="resolveIcon(s.icon)"
                                    v-else-if="resolveIcon(s.icon)"
                                    class="size-4"
                                />
                                <template v-else>{{ i + 1 }}</template>
                            </StepperIndicator>
                        </button>
                    </StepperTrigger>
                    <StepperSeparator
                        v-if="i < steps.length - 1"
                        class="my-1 w-0.5 grow rounded-full bg-border group-data-[state=completed]:bg-primary"
                    />
                </div>

                <!-- text -->
                <StepperTrigger as-child @click="goTo(i)">
                    <button type="button" class="min-w-0 pb-6 text-left">
                        <StepperTitle
                            class="text-sm font-medium block text-foreground"
                            >{{ s.label }}</StepperTitle
                        >
                        <StepperDescription
                            v-if="s.description"
                            class="text-xs block text-muted-foreground"
                            >{{ s.description }}</StepperDescription
                        >
                    </button>
                </StepperTrigger>
            </StepperItem>
        </StepperRoot>

        <!-- simple: progress bar + counter -->
        <div v-else-if="variant === 'simple'" class="mb-6 space-y-2">
            <div class="text-sm flex items-center justify-between">
                <span class="font-medium text-foreground">{{
                    currentStep.label
                }}</span>
                <span class="text-muted-foreground">
                    {{
                        t('kinetix.wizard_step_of', {
                            current: current + 1,
                            total,
                        })
                    }}
                </span>
            </div>
            <div class="h-2 w-full overflow-hidden rounded-full bg-muted">
                <div
                    class="h-full rounded-full bg-primary transition-all"
                    :style="{ width: `${percent}%` }"
                />
            </div>
        </div>

        <!-- panels: row of filled pills -->
        <div
            v-else-if="variant === 'panels'"
            class="mb-6 gap-2 flex flex-wrap"
            :class="fullWidth ? '' : 'justify-center'"
        >
            <button
                v-for="(s, i) in steps"
                :key="stepKey(s, i)"
                type="button"
                :disabled="stepDisabled(i)"
                class="gap-2 rounded-lg px-3 py-2 text-sm flex items-center border text-left transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                :class="[
                    fullWidth ? 'flex-1' : '',
                    hasError(i)
                        ? 'border-destructive bg-destructive/5 text-destructive'
                        : statusOf(i) === 'active'
                          ? 'shadow-sm border-primary bg-primary text-primary-foreground'
                          : statusOf(i) === 'complete'
                            ? 'border-primary/40 bg-primary/10 text-foreground'
                            : 'border-border bg-card text-muted-foreground',
                ]"
                @click="goTo(i)"
            >
                <span
                    class="size-5 text-xs font-semibold flex shrink-0 items-center justify-center rounded-full"
                    :class="
                        hasError(i)
                            ? 'text-white bg-destructive'
                            : statusOf(i) === 'active'
                              ? 'bg-primary-foreground/20'
                              : 'bg-muted'
                    "
                >
                    <Check v-if="statusOf(i) === 'complete'" class="size-3" />
                    <template v-else>{{ i + 1 }}</template>
                </span>
                <span class="font-medium truncate">{{ s.label }}</span>
            </button>
        </div>

        <!-- vertical: left rail -->
        <ol
            v-else-if="variant === 'vertical'"
            class="gap-1 md:w-56 flex shrink-0 flex-col"
        >
            <li v-for="(s, i) in steps" :key="stepKey(s, i)">
                <button
                    type="button"
                    :disabled="stepDisabled(i)"
                    class="gap-3 p-2 flex w-full items-start rounded-md text-left transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                    :class="
                        statusOf(i) === 'active'
                            ? 'bg-accent'
                            : 'hover:bg-accent/50'
                    "
                    @click="goTo(i)"
                >
                    <span
                        class="mt-0.5 size-6 text-xs font-semibold flex shrink-0 items-center justify-center rounded-full"
                        :class="
                            hasError(i)
                                ? 'text-white bg-destructive'
                                : statusOf(i) === 'upcoming'
                                  ? 'border border-border text-muted-foreground'
                                  : 'bg-primary text-primary-foreground'
                        "
                    >
                        <Check
                            v-if="statusOf(i) === 'complete'"
                            class="size-3.5"
                        />
                        <template v-else>{{ i + 1 }}</template>
                    </span>
                    <span class="min-w-0">
                        <span
                            class="text-sm font-medium block text-foreground"
                            >{{ s.label }}</span
                        >
                        <span
                            v-if="s.description"
                            class="text-xs block text-muted-foreground"
                            >{{ s.description }}</span
                        >
                    </span>
                </button>
            </li>
        </ol>

        <!-- default / gradient: horizontal circles + connectors -->
        <div
            v-else
            class="mb-6 flex items-center"
            :class="fullWidth ? '' : 'justify-center'"
        >
            <template v-for="(s, i) in steps" :key="stepKey(s, i)">
                <button
                    type="button"
                    :disabled="stepDisabled(i)"
                    class="gap-1.5 flex flex-col items-center disabled:cursor-not-allowed"
                    @click="goTo(i)"
                >
                    <span
                        class="size-9 text-sm font-semibold flex items-center justify-center rounded-full transition-all"
                        :class="
                            hasError(i)
                                ? 'text-white bg-destructive ring-2 ring-destructive/30'
                                : variant === 'gradient'
                                  ? statusOf(i) !== 'upcoming'
                                      ? 'to-fuchsia-500 text-white shadow-md bg-gradient-to-br from-primary'
                                      : 'border border-border bg-card text-muted-foreground'
                                  : statusOf(i) === 'active'
                                    ? 'bg-primary text-primary-foreground ring-[3px] ring-ring/30'
                                    : statusOf(i) === 'complete'
                                      ? 'bg-primary text-primary-foreground'
                                      : 'border border-border bg-card text-muted-foreground'
                        "
                    >
                        <Check
                            v-if="statusOf(i) === 'complete'"
                            class="size-4"
                        />
                        <component
                            :is="resolveIcon(s.icon)"
                            v-else-if="resolveIcon(s.icon)"
                            class="size-4"
                        />
                        <template v-else>{{ i + 1 }}</template>
                    </span>
                    <span
                        class="text-xs font-medium sm:block hidden"
                        :class="
                            statusOf(i) === 'upcoming'
                                ? 'text-muted-foreground'
                                : 'text-foreground'
                        "
                        >{{ s.label }}</span
                    >
                </button>
                <div
                    v-if="i < steps.length - 1"
                    class="mx-2 h-0.5 rounded-full transition-colors"
                    :class="[
                        fullWidth ? 'flex-1' : 'w-10',
                        i < current
                            ? variant === 'gradient'
                                ? 'to-fuchsia-500 bg-gradient-to-r from-primary'
                                : 'bg-primary'
                            : 'bg-border',
                    ]"
                />
            </template>
        </div>

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
