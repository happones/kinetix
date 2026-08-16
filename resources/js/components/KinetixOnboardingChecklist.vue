<script setup lang="ts">
import { computed, onMounted } from 'vue';
import type { Component } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixOnboarding } from '@/composables/useKinetixOnboarding';
import OnboardingChecklistCard from './Onboarding/OnboardingChecklistCard.vue';
import OnboardingChecklistSidebar from './Onboarding/OnboardingChecklistSidebar.vue';

/**
 * Drop-in first-run setup checklist: shows declared steps with completion state,
 * a progress bar, per-step CTAs, "mark done" for manual steps, and a dismiss
 * control. Self-contained — talks to the `onboarding` endpoints via
 * `useKinetixOnboarding`. Hides itself once dismissed or fully complete.
 *
 * Two presentations share that one data pipeline: the default `card` for a
 * dashboard or settings page, and `sidebar` — a condensed block sized for a
 * shadcn `<SidebarFooter>`, which folds away when the rail collapses to icons.
 */
const props = withDefaults(
    defineProps<{
        /** Presentation: full-width card, or a condensed sidebar block. */
        variant?: 'card' | 'sidebar';
        /** Hide the checklist automatically once every step is complete. */
        hideWhenComplete?: boolean;
    }>(),
    { variant: 'card', hideWhenComplete: true },
);

const VARIANTS: Record<'card' | 'sidebar', Component> = {
    card: OnboardingChecklistCard,
    sidebar: OnboardingChecklistSidebar,
};

const { t } = useI18n();
const { state, load, complete, dismiss } = useKinetixOnboarding();

onMounted(load);

const percent = computed(() => {
    if (!state.value || state.value.total === 0) {
        return 0;
    }

    return Math.round((state.value.completedCount / state.value.total) * 100);
});

const progressText = computed(() =>
    t('kinetix.onboarding_progress', {
        completed: state.value?.completedCount ?? 0,
        total: state.value?.total ?? 0,
    }),
);

const visible = computed(() => {
    if (!state.value || state.value.dismissed) {
        return false;
    }

    if (props.hideWhenComplete && state.value.complete) {
        return false;
    }

    return state.value.total > 0;
});
</script>

<template>
    <component
        :is="VARIANTS[props.variant]"
        v-if="visible && state"
        :state="state"
        :percent="percent"
        :progress-text="progressText"
        @complete="complete"
        @dismiss="dismiss"
    />
</template>
