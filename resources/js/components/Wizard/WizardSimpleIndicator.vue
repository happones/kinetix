<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
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

defineEmits<{ (e: 'goto', index: number): void }>();

const { t } = useI18n();

const total = computed(() => props.steps.length);
const currentStep = computed(() => props.steps[props.current]);
const percent = computed(() =>
    total.value <= 1
        ? 100
        : Math.round((props.current / (total.value - 1)) * 100),
);
</script>

<template>
    <!-- simple: progress bar + counter -->
    <div class="mb-6 space-y-2">
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
</template>
