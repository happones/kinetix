<script setup lang="ts">
import { X } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixFocusTrap } from '@/composables/useKinetixFocusTrap';
import type { KinetixSheetSide } from '@/types/kinetix';
import ScrollArea from './primitives/ScrollArea.vue';

/**
 * A shadcn-style slide-in panel (Sheet) — a Dialog alternative anchored to an
 * edge of the viewport instead of centered. Hand-rolled Teleport + Transition
 * (same leak-safe pattern as `KinetixConfirmModal`: the escape-key listener and
 * focus trap are only attached while open and always removed on unmount).
 */
const props = withDefaults(
    defineProps<{
        open: boolean;
        side?: KinetixSheetSide;
        title?: string | null;
        description?: string | null;
        /** Override the default width (left/right) or height (top/bottom) class. */
        size?: string | null;
    }>(),
    { side: 'right', title: null, description: null, size: null },
);

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'close'): void;
}>();

const { t } = useI18n();

const isMounted = ref(false);
onMounted(() => {
    isMounted.value = true;
});

function close(): void {
    emit('update:open', false);
    emit('close');
}

const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
        close();
    }
};

watch(
    () => props.open,
    (isOpen) => {
        if (typeof window === 'undefined') {
            return;
        }

        if (isOpen) {
            window.addEventListener('keydown', onKeydown);

            return;
        }

        window.removeEventListener('keydown', onKeydown);
    },
    // `immediate` so Escape works even when mounted already-open, not just on
    // a later open transition.
    { immediate: true },
);

onBeforeUnmount(() => {
    if (typeof window !== 'undefined') {
        window.removeEventListener('keydown', onKeydown);
    }
});

// Focus moves into the panel on open, Tab cycles inside it, and the opener gets
// focus back on close.
const panelEl = ref<HTMLElement | null>(null);
const { headingId } = useKinetixFocusTrap({
    active: () => props.open,
    container: () => panelEl.value,
});

const positionClass = computed(
    () =>
        ({
            right: 'inset-y-0 right-0 h-full border-l',
            left: 'inset-y-0 left-0 h-full border-r',
            top: 'inset-x-0 top-0 w-full border-b',
            bottom: 'inset-x-0 bottom-0 w-full border-t',
        })[props.side],
);

const sizeClass = computed(
    () =>
        props.size ??
        {
            right: 'w-full sm:max-w-sm',
            left: 'w-full sm:max-w-sm',
            top: 'max-h-[80vh]',
            bottom: 'max-h-[80vh]',
        }[props.side],
);

const enterFromClass = computed(
    () =>
        ({
            right: 'translate-x-full',
            left: '-translate-x-full',
            top: '-translate-y-full',
            bottom: 'translate-y-full',
        })[props.side],
);
</script>

<template>
    <Teleport v-if="isMounted" to="body">
        <div
            v-if="open"
            class="inset-0 fixed z-[var(--kinetix-z-modal,100)]"
            role="dialog"
            aria-modal="true"
            :aria-labelledby="title ? headingId : undefined"
        >
            <Transition
                enter-active-class="transition-opacity duration-200"
                enter-from-class="opacity-0"
                leave-active-class="transition-opacity duration-200"
                leave-to-class="opacity-0"
                appear
            >
                <div class="inset-0 bg-black/80 absolute" @click="close" />
            </Transition>

            <Transition
                enter-active-class="transition-transform duration-500 ease-in-out"
                :enter-from-class="enterFromClass"
                leave-active-class="transition-transform duration-300 ease-in-out"
                :leave-to-class="enterFromClass"
                appear
            >
                <div
                    ref="panelEl"
                    tabindex="-1"
                    class="p-6 shadow-lg absolute flex flex-col overflow-hidden border-border bg-background outline-none"
                    :class="[positionClass, sizeClass]"
                >
                    <div class="mb-4 flex items-start justify-between">
                        <slot name="header">
                            <div class="min-w-0">
                                <h2
                                    v-if="title"
                                    :id="headingId"
                                    class="text-base font-semibold tracking-tight text-foreground"
                                >
                                    {{ title }}
                                </h2>
                                <p
                                    v-if="description"
                                    class="mt-1 text-sm text-muted-foreground"
                                >
                                    {{ description }}
                                </p>
                            </div>
                        </slot>
                        <button
                            type="button"
                            class="p-1 shrink-0 rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                            :aria-label="t('kinetix.close')"
                            @click="close"
                        >
                            <X class="size-4" />
                        </button>
                    </div>

                    <!-- `-mr-3 pr-3` parks the overlay scrollbar in the
                         panel's right padding instead of over the content. -->
                    <ScrollArea type="auto" class="-mr-3 min-h-0 pr-3 flex-1">
                        <slot />
                    </ScrollArea>

                    <div
                        v-if="$slots.footer"
                        class="mt-4 pt-4 border-t border-border"
                    >
                        <slot name="footer" />
                    </div>
                </div>
            </Transition>
        </div>
    </Teleport>
</template>
