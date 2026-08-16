<script setup lang="ts">
import { CheckCircle2, Circle, X } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { resolveIcon } from '@/composables/useKinetixIcons';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';
import type { KinetixOnboarding, KinetixOnboardingStep } from '@/types/kinetix';
import { cn } from '../primitives/cn';

/**
 * The condensed `sidebar` presentation of the onboarding checklist, sized for a
 * shadcn `<SidebarFooter>` / `<SidebarGroup>`: a one-line header with the
 * "1 of 3" counter and a dismiss control, a hairline progress bar, and one
 * compact row per step. Descriptions are dropped — a navigation rail has no
 * room for them, and the step title plus its CTA carry the intent.
 *
 * Each row keeps BOTH affordances the card variant exposes, without nesting
 * interactive elements: the leading circle is the "mark as done" button for
 * manual steps, while the title stretches its own link across the row
 * (`after:inset-0`) so clicking anywhere else follows the step's CTA.
 *
 * `group-data-[collapsible=icon]:hidden` is shadcn's own sidebar contract, so
 * the block folds away by itself when the rail collapses to icons; outside a
 * sidebar the class simply never matches.
 *
 * The dark surface is `muted/40` rather than `card`: shadcn's `--sidebar` and
 * `--card` are the SAME value in dark mode, so a plain card would read as a
 * bare outline on the rail instead of a block lifted off it.
 */
const props = defineProps<{
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

/** The header counter is the terse twin of `progressText` — "1 of 3". */
const counterText = computed(() =>
    t('kinetix.onboarding_progress_short', {
        completed: props.state.completedCount,
        total: props.state.total,
    }),
);

/** A step opens its CTA only while it is still pending — done rows are inert. */
function stepHref(step: KinetixOnboardingStep): string | null {
    if (step.completed) {
        return null;
    }

    return step.ctaHref;
}

/** Manual steps can be ticked off from the leading circle. */
function isTickable(step: KinetixOnboardingStep): boolean {
    return step.manual && !step.completed;
}
</script>

<template>
    <section
        class="gap-2 p-2 rounded-lg shadow-xs flex flex-col border border-border bg-card text-card-foreground group-data-[collapsible=icon]:hidden dark:bg-muted/40"
        :aria-label="t('kinetix.onboarding_title')"
    >
        <!-- Header: title, counter, dismiss -->
        <div class="gap-1 px-1 flex items-start">
            <h2
                class="min-w-0 pt-0.5 text-xs font-medium flex-1 text-foreground"
            >
                {{ t('kinetix.onboarding_title') }}
            </h2>
            <span
                class="pt-0.5 text-xs shrink-0 text-muted-foreground tabular-nums"
            >
                {{ counterText }}
            </span>
            <button
                type="button"
                :aria-label="t('kinetix.onboarding_dismiss')"
                :class="
                    cn(
                        buttonVariants({ variant: 'ghost', size: 'icon-sm' }),
                        'size-6 shrink-0 cursor-pointer text-muted-foreground hover:text-foreground',
                    )
                "
                @click="emit('dismiss')"
            >
                <X class="size-3.5" aria-hidden="true" />
            </button>
        </div>

        <!-- Progress bar -->
        <div
            class="mx-1 h-1 overflow-hidden rounded-full bg-muted"
            role="progressbar"
            :aria-label="t('kinetix.onboarding_title')"
            :aria-valuenow="percent"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-valuetext="progressText"
        >
            <div
                class="h-full rounded-full bg-primary transition-[width] duration-300"
                :style="{ width: `${percent}%` }"
            />
        </div>

        <!-- Steps -->
        <ul class="flex flex-col">
            <li
                v-for="step in state.steps"
                :key="step.key"
                class="gap-1.5 px-1 py-0.5 relative flex items-start rounded-md"
                :class="{
                    'hover:bg-accent': stepHref(step) || isTickable(step),
                }"
            >
                <button
                    v-if="isTickable(step)"
                    type="button"
                    :aria-label="
                        t('kinetix.onboarding_mark_done_step', {
                            step: step.title,
                        })
                    "
                    class="size-6 relative z-10 grid shrink-0 cursor-pointer place-items-center rounded-full text-muted-foreground/70 transition-colors outline-none hover:text-primary focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    @click="emit('complete', step.key)"
                >
                    <Circle class="size-4" aria-hidden="true" />
                </button>
                <span
                    v-else
                    class="size-6 grid shrink-0 place-items-center"
                    :class="
                        step.completed
                            ? 'text-primary'
                            : 'text-muted-foreground/70'
                    "
                >
                    <component
                        :is="step.completed ? CheckCircle2 : Circle"
                        class="size-4"
                        aria-hidden="true"
                    />
                </span>

                <div class="min-w-0 py-0.5 flex-1">
                    <a
                        v-if="stepHref(step)"
                        :href="stepHref(step)!"
                        class="rounded-sm text-sm after:inset-0 text-foreground outline-none after:absolute after:rounded-md focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        {{ step.title }}
                    </a>
                    <span
                        v-else
                        class="text-sm"
                        :class="
                            step.completed
                                ? 'text-muted-foreground line-through'
                                : 'text-foreground'
                        "
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

                <component
                    :is="resolveIcon(step.icon)"
                    v-if="resolveIcon(step.icon)"
                    class="mt-1.5 size-3.5 shrink-0 text-muted-foreground/70"
                    aria-hidden="true"
                />
            </li>
        </ul>
    </section>
</template>
