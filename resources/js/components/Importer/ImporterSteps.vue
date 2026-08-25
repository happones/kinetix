<script setup lang="ts">
import { Check } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { KINETIX_IMPORTER_STEPS } from '@/composables/useKinetixImporter';
import type { KinetixImporterStep } from '@/composables/useKinetixImporter';

/**
 * The import wizard's step indicator.
 *
 * Rendered as an ordered list so assistive tech reads it as the sequence it
 * is, with `aria-current="step"` on the active one. Completed steps are
 * buttons (you can jump back), later steps are not — you cannot map columns
 * for a file that hasn't been parsed yet.
 */
const props = defineProps<{
    current: KinetixImporterStep;
    /** Steps the user is allowed to jump back to. */
    reachable: KinetixImporterStep[];
}>();

const emit = defineEmits<{ (e: 'select', step: KinetixImporterStep): void }>();

const { t } = useI18n();

const labels: Record<KinetixImporterStep, string> = {
    file: 'kinetix.import_step_file',
    mapping: 'kinetix.import_step_mapping',
    review: 'kinetix.import_step_review',
};

const currentIndex = computed(() =>
    KINETIX_IMPORTER_STEPS.indexOf(props.current),
);

const isDone = (index: number): boolean => index < currentIndex.value;

const canSelect = (step: KinetixImporterStep, index: number): boolean =>
    index !== currentIndex.value && props.reachable.includes(step);
</script>

<template>
    <ol class="gap-1 text-sm flex items-center">
        <li
            v-for="(step, index) in KINETIX_IMPORTER_STEPS"
            :key="step"
            class="gap-1 min-w-0 flex items-center"
        >
            <component
                :is="canSelect(step, index) ? 'button' : 'span'"
                :type="canSelect(step, index) ? 'button' : undefined"
                :aria-current="step === current ? 'step' : undefined"
                :aria-disabled="
                    !canSelect(step, index) && step !== current
                        ? true
                        : undefined
                "
                class="h-8 gap-2 px-2 flex items-center rounded-md transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                :class="[
                    step === current
                        ? 'font-medium text-foreground'
                        : 'text-muted-foreground',
                    canSelect(step, index)
                        ? 'cursor-pointer hover:bg-accent hover:text-foreground'
                        : '',
                ]"
                @click="canSelect(step, index) && emit('select', step)"
            >
                <span
                    class="size-5 font-semibold inline-flex shrink-0 items-center justify-center rounded-full text-[11px]"
                    :class="
                        isDone(index)
                            ? 'bg-primary text-primary-foreground'
                            : step === current
                              ? 'bg-primary/15 text-primary'
                              : 'bg-muted text-muted-foreground'
                    "
                >
                    <Check
                        v-if="isDone(index)"
                        class="size-3"
                        aria-hidden="true"
                    />
                    <template v-else>{{ index + 1 }}</template>
                </span>
                <span class="truncate">{{ t(labels[step]) }}</span>
            </component>

            <span
                v-if="index < KINETIX_IMPORTER_STEPS.length - 1"
                class="w-4 sm:w-8 h-px shrink-0 bg-border"
                aria-hidden="true"
            />
        </li>
    </ol>
</template>
