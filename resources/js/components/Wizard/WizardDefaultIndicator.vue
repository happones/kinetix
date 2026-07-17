<script setup lang="ts">
import { Check } from '@lucide/vue';
import { resolveIcon } from '@/composables/useKinetixIcons';
import { useKinetixWizardStatus } from '@/composables/useKinetixWizardStatus';
import type { KinetixWizardStep, KinetixWizardStepLayout } from '@/types';

const props = defineProps<{
    steps: KinetixWizardStep[];
    current: number;
    maxReached: number;
    errorSteps: number[];
    linear: boolean;
    fullWidth: boolean;
    stepLayout: KinetixWizardStepLayout;
    orientation: 'horizontal' | 'vertical';
    /** Use the gradient palette for filled indicators/connectors. */
    gradient: boolean;
}>();

const emit = defineEmits<{ (e: 'goto', index: number): void }>();

const { statusOf, hasError, stepDisabled, stepKey } = useKinetixWizardStatus({
    current: () => props.current,
    maxReached: () => props.maxReached,
    errorSteps: () => props.errorSteps,
    linear: () => props.linear,
});
</script>

<template>
    <!-- default / gradient: horizontal circles + connectors -->
    <div
        class="mb-6 flex items-center"
        :class="fullWidth ? '' : 'justify-center'"
    >
        <template v-for="(s, i) in steps" :key="stepKey(s, i)">
            <button
                type="button"
                :disabled="stepDisabled(i)"
                class="gap-1.5 flex flex-col items-center disabled:cursor-not-allowed"
                @click="emit('goto', i)"
            >
                <span
                    class="size-9 text-sm font-semibold flex items-center justify-center rounded-full transition-all"
                    :class="
                        hasError(i)
                            ? 'text-white bg-destructive ring-2 ring-destructive/30'
                            : gradient
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
                    <Check v-if="statusOf(i) === 'complete'" class="size-4" />
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
                        ? gradient
                            ? 'to-fuchsia-500 bg-gradient-to-r from-primary'
                            : 'bg-primary'
                        : 'bg-border',
                ]"
            />
        </template>
    </div>
</template>
