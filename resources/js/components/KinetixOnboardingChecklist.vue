<script setup lang="ts">
import { CheckCircle2, Circle } from '@lucide/vue';
import { computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { resolveIcon } from '@/composables/useKinetixIcons';
import { useKinetixOnboarding } from '@/composables/useKinetixOnboarding';
import { buttonVariants } from '@/composables/useShadcnVariants';
import type { KinetixOnboardingStep } from '@/types';

/**
 * Drop-in first-run setup checklist: shows declared steps with completion state,
 * a progress bar, per-step CTAs, "mark done" for manual steps, and a dismiss
 * control. Self-contained — talks to the `onboarding` endpoints via
 * `useKinetixOnboarding`. Hides itself once dismissed or fully complete.
 */
const props = withDefaults(
    defineProps<{
        /** Hide the card automatically once every step is complete. */
        hideWhenComplete?: boolean;
    }>(),
    { hideWhenComplete: true },
);

const { t } = useI18n();
const { state, load, complete, dismiss } = useKinetixOnboarding();

onMounted(load);

const percent = computed(() => {
    if (!state.value || state.value.total === 0) {
        return 0;
    }

    return Math.round((state.value.completedCount / state.value.total) * 100);
});

const visible = computed(() => {
    if (!state.value || state.value.dismissed) {
        return false;
    }

    if (props.hideWhenComplete && state.value.complete) {
        return false;
    }

    return state.value.total > 0;
});

function onMarkDone(step: KinetixOnboardingStep): void {
    if (!step.completed && step.manual) {
        complete(step.key);
    }
}
</script>

<template>
    <div
        v-if="visible && state"
        class="space-y-4 rounded-lg p-4 border border-border bg-card"
    >
        <div class="gap-3 flex items-start justify-between">
            <div>
                <h2 class="text-lg font-semibold text-foreground">
                    {{ t('kinetix.onboarding_title') }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{
                        t('kinetix.onboarding_progress', {
                            completed: state.completedCount,
                            total: state.total,
                        })
                    }}
                </p>
            </div>
            <button
                type="button"
                :class="buttonVariants({ variant: 'ghost', size: 'sm' })"
                @click="dismiss"
            >
                {{ t('kinetix.onboarding_dismiss') }}
            </button>
        </div>

        <!-- Progress bar -->
        <div
            class="h-2 w-full overflow-hidden rounded-full bg-muted"
            role="progressbar"
            :aria-valuenow="percent"
            aria-valuemin="0"
            aria-valuemax="100"
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
                />
                <div class="min-w-0 flex-1">
                    <div class="gap-2 flex items-center">
                        <component
                            :is="resolveIcon(step.icon)"
                            v-if="resolveIcon(step.icon)"
                            class="size-4 text-muted-foreground"
                        />
                        <span
                            class="text-sm font-medium text-foreground"
                            :class="{
                                'line-through opacity-70': step.completed,
                            }"
                        >
                            {{ step.title }}
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
                    <button
                        v-if="step.manual && !step.completed"
                        type="button"
                        :class="
                            buttonVariants({ variant: 'ghost', size: 'sm' })
                        "
                        @click="onMarkDone(step)"
                    >
                        {{ t('kinetix.onboarding_mark_done') }}
                    </button>
                </div>
            </li>
        </ul>
    </div>
</template>
