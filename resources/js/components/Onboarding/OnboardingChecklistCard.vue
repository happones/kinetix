<script setup lang="ts">
import { CheckCircle2, Circle } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { resolveIcon } from '@/composables/useKinetixIcons';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';
import type { KinetixOnboarding, KinetixOnboardingStep } from '@/types/kinetix';
import KinetixButton from '../KinetixButton.vue';

/**
 * The full-width `card` presentation of the onboarding checklist: a heading
 * with the progress counter, a progress bar, and one row per step carrying its
 * icon, description, CTA and "mark as done" control. Meant for a dashboard or
 * a settings page — see `OnboardingChecklistSidebar` for the condensed
 * navigation-rail presentation.
 */
defineProps<{
    state: KinetixOnboarding;
    /** Completion as a whole percentage, precomputed by the parent. */
    percent: number;
    /** Spoken progress ("1 of 3 complete"), shared by both variants. */
    progressText: string;
}>();

const emit = defineEmits<{
    (e: 'complete', step: string): void;
    (e: 'dismiss'): void;
}>();

const { t } = useI18n();

function onMarkDone(step: KinetixOnboardingStep): void {
    if (step.completed || !step.manual) {
        return;
    }

    emit('complete', step.key);
}
</script>

<template>
    <section
        class="space-y-4 rounded-lg p-4 border border-border bg-card"
        :aria-label="t('kinetix.onboarding_title')"
    >
        <div class="gap-3 flex items-start justify-between">
            <div>
                <h2 class="text-lg font-semibold text-foreground">
                    {{ t('kinetix.onboarding_title') }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{ progressText }}
                </p>
            </div>
            <KinetixButton variant="ghost" size="sm" @click="emit('dismiss')">
                {{ t('kinetix.onboarding_dismiss') }}
            </KinetixButton>
        </div>

        <!-- Progress bar -->
        <div
            class="h-2 w-full overflow-hidden rounded-full bg-muted"
            role="progressbar"
            :aria-label="t('kinetix.onboarding_title')"
            :aria-valuenow="percent"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-valuetext="progressText"
        >
            <div
                class="h-full rounded-full bg-primary transition-all"
                :style="{ width: `${percent}%` }"
            />
        </div>

        <!-- Steps -->
        <ul class="space-y-2">
            <li
                v-for="step in state.steps"
                :key="step.key"
                class="gap-3 p-3 flex items-start rounded-md border border-border"
            >
                <component
                    :is="step.completed ? CheckCircle2 : Circle"
                    class="mt-0.5 size-5 shrink-0"
                    :class="
                        step.completed
                            ? 'text-primary'
                            : 'text-muted-foreground/60'
                    "
                    aria-hidden="true"
                />
                <div class="min-w-0 flex-1">
                    <div class="gap-2 flex items-center">
                        <component
                            :is="resolveIcon(step.icon)"
                            v-if="resolveIcon(step.icon)"
                            class="size-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <span
                            class="text-sm font-medium text-foreground"
                            :class="{
                                'line-through opacity-70': step.completed,
                            }"
                        >
                            {{ step.title }}
                        </span>
                        <span class="sr-only">
                            {{
                                step.completed
                                    ? t('kinetix.onboarding_status_completed')
                                    : t('kinetix.onboarding_status_pending')
                            }}
                        </span>
                    </div>
                    <p
                        v-if="step.description"
                        class="mt-0.5 text-sm text-muted-foreground"
                    >
                        {{ step.description }}
                    </p>
                </div>
                <div class="gap-2 flex shrink-0 items-center">
                    <a
                        v-if="step.ctaHref && !step.completed"
                        :href="step.ctaHref"
                        :class="
                            buttonVariants({ variant: 'outline', size: 'sm' })
                        "
                    >
                        {{ step.ctaLabel ?? t('kinetix.onboarding_go') }}
                    </a>
                    <KinetixButton
                        v-if="step.manual && !step.completed"
                        variant="ghost"
                        size="sm"
                        @click="onMarkDone(step)"
                    >
                        {{ t('kinetix.onboarding_mark_done') }}
                    </KinetixButton>
                </div>
            </li>
        </ul>
    </section>
</template>
