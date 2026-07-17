<script setup lang="ts">
import { Check } from '@lucide/vue';
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
    <!-- vertical: left rail -->
    <ol class="gap-1 md:w-56 flex shrink-0 flex-col">
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
                @click="emit('goto', i)"
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
                    <Check v-if="statusOf(i) === 'complete'" class="size-3.5" />
                    <template v-else>{{ i + 1 }}</template>
                </span>
                <span class="min-w-0">
                    <span class="text-sm font-medium block text-foreground">{{
                        s.label
                    }}</span>
                    <span
                        v-if="s.description"
                        class="text-xs block text-muted-foreground"
                        >{{ s.description }}</span
                    >
                </span>
            </button>
        </li>
    </ol>
</template>
