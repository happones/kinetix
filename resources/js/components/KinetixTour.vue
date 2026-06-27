<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixTour } from '@/composables/useKinetixTour';
import type { KinetixTourStep } from '@/composables/useKinetixTour';
import { buttonVariants } from '@/composables/useShadcnVariants';

/**
 * Dependency-free product tour. Highlights each step's target element with a
 * spotlight ring and shows a positioned tooltip with next/prev/skip controls.
 * Auto-starts once per `id` (persisted in localStorage) unless `auto` is false.
 *
 * Targets are plain CSS selectors, e.g. add `data-tour="create"` to a button
 * and pass `{ target: '[data-tour=create]', title: '…' }`.
 */
const props = withDefaults(
    defineProps<{
        id: string;
        steps: KinetixTourStep[];
        /** Auto-start the first time this tour id is seen. */
        auto?: boolean;
    }>(),
    { auto: true },
);

const { t } = useI18n();
const tour = useKinetixTour(props.id, props.steps);

/** Bounding box of the current target (viewport coordinates). */
const rect = ref<DOMRect | null>(null);

function measure(): void {
    const step = tour.current.value;

    if (!step) {
        rect.value = null;

        return;
    }

    const el = document.querySelector(step.target);

    if (el) {
        el.scrollIntoView({ block: 'center', behavior: 'smooth' });
        rect.value = el.getBoundingClientRect();
    } else {
        // Unknown target: center the tooltip with no spotlight.
        rect.value = null;
    }
}

watch(
    () => [tour.active.value, tour.index.value],
    async () => {
        if (tour.active.value) {
            await nextTick();
            measure();
        }
    },
);

function onResize(): void {
    if (tour.active.value) {
        measure();
    }
}

onMounted(() => {
    window.addEventListener('resize', onResize, { passive: true });
    window.addEventListener('scroll', onResize, { passive: true });

    if (props.auto) {
        tour.startOnce();
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', onResize);
    window.removeEventListener('scroll', onResize);
});

defineExpose({ start: tour.start, reset: tour.reset });
</script>

<template>
    <Teleport to="body">
        <div
            v-if="tour.active.value && tour.current.value"
            class="inset-0 fixed z-[100]"
            role="dialog"
            aria-modal="true"
        >
            <!-- Dimmed backdrop -->
            <div class="inset-0 bg-black/50 absolute" @click="tour.skip()" />

            <!-- Spotlight ring around the target -->
            <div
                v-if="rect"
                class="pointer-events-none absolute rounded-md border-2 border-primary bg-background/5 transition-all"
                :style="{
                    top: `${rect.top - 4}px`,
                    left: `${rect.left - 4}px`,
                    width: `${rect.width + 8}px`,
                    height: `${rect.height + 8}px`,
                }"
            />

            <!-- Tooltip -->
            <div
                class="w-72 rounded-lg p-4 shadow-lg absolute max-w-[calc(100vw-2rem)] border border-border bg-popover"
                :style="
                    rect
                        ? {
                              top: `${rect.bottom + 12}px`,
                              left: `${rect.left}px`,
                          }
                        : {
                              top: '50%',
                              left: '50%',
                              transform: 'translate(-50%, -50%)',
                          }
                "
            >
                <h3 class="text-sm font-semibold text-foreground">
                    {{ tour.current.value.title }}
                </h3>
                <p
                    v-if="tour.current.value.description"
                    class="mt-1 text-sm text-muted-foreground"
                >
                    {{ tour.current.value.description }}
                </p>

                <div class="mt-4 flex items-center justify-between">
                    <span class="text-xs text-muted-foreground">
                        {{ tour.index.value + 1 }} / {{ tour.steps.length }}
                    </span>
                    <div class="gap-2 flex items-center">
                        <button
                            type="button"
                            :class="
                                buttonVariants({ variant: 'ghost', size: 'sm' })
                            "
                            @click="tour.skip()"
                        >
                            {{ t('kinetix.tour_skip') }}
                        </button>
                        <button
                            v-if="!tour.isFirst.value"
                            type="button"
                            :class="
                                buttonVariants({
                                    variant: 'outline',
                                    size: 'sm',
                                })
                            "
                            @click="tour.prev()"
                        >
                            {{ t('kinetix.tour_back') }}
                        </button>
                        <button
                            type="button"
                            :class="buttonVariants({ size: 'sm' })"
                            @click="tour.next()"
                        >
                            {{
                                tour.isLast.value
                                    ? t('kinetix.tour_done')
                                    : t('kinetix.tour_next')
                            }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
