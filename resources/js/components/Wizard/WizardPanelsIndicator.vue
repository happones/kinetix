<script setup lang="ts">
import { Check } from '@lucide/vue';
import { useKinetixWizardStatus } from '@/composables/useKinetixWizardStatus';
import type {
    KinetixWizardStep,
    KinetixWizardStepLayout,
} from '@/types/kinetix';

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
    <!-- panels: row of filled pills -->
    <div
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
            @click="emit('goto', i)"
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
</template>
