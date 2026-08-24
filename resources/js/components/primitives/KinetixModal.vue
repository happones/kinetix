<script setup lang="ts">
import { X } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixFocusTrap } from '@/composables/useKinetixFocusTrap';
import ScrollArea from './ScrollArea.vue';

/**
 * The shared modal shell, styled after shadcn-vue's new-york-v4 Dialog so
 * every Kinetix modal carries the same design line: `bg-black/80` overlay and
 * panel animating with `fade + zoom-95` over 200ms, `bg-background` panel
 * (rounded-lg border shadow-lg), the v4 close button, and the v4
 * header/footer stacks. Deviations from the registry are deliberate:
 * z-index uses the Kinetix layer scale (never a raw utility), and focus
 * trapping/escape/aria ride on useKinetixFocusTrap.
 *
 * The shell is ALWAYS bounded: the fixed wrapper scrolls, so a panel taller
 * than the viewport (a long form) scrolls whole instead of overflowing off
 * screen with its top and bottom unreachable. `scrollBody` is the opt-in for
 * the other layout — header and footer pinned, only the body scrolling, in a
 * shadcn ScrollArea — never the only thing keeping content reachable.
 *
 * Slots: `default` (body), `header` (replaces title/description), `footer`
 * (v4 footer stack: column-reverse on mobile, right-aligned row on ≥sm).
 */
const props = withDefaults(
    defineProps<{
        open: boolean;
        title?: string | null;
        description?: string | null;
        /** Tailwind max-width class for the panel (v4 default: sm:max-w-lg). */
        maxWidth?: string;
        showCloseButton?: boolean;
        /** Block dismissal (overlay/escape/close) while an action runs. */
        processing?: boolean;
        /**
         * Pin the header and footer and scroll only the body slot (in a
         * shadcn ScrollArea), instead of scrolling the whole panel.
         */
        scrollBody?: boolean;
    }>(),
    {
        title: null,
        description: null,
        maxWidth: 'sm:max-w-lg',
        showCloseButton: true,
        processing: false,
        scrollBody: false,
    },
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

const close = () => {
    if (props.processing) {
        return;
    }

    emit('update:open', false);
    emit('close');
};

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
);

onBeforeUnmount(() => {
    if (typeof window !== 'undefined') {
        window.removeEventListener('keydown', onKeydown);
    }
});

const panelEl = ref<HTMLElement | null>(null);
const { headingId } = useKinetixFocusTrap({
    active: () => props.open,
    container: () => panelEl.value,
});
</script>

<template>
    <Teleport v-if="isMounted" to="body">
        <!-- Overlay: v4 fade, 200ms. -->
        <Transition
            enter-active-class="animate-in fade-in-0 duration-200"
            leave-active-class="animate-out fade-out-0 duration-200"
        >
            <div
                v-if="open"
                class="inset-0 bg-black/80 fixed z-[var(--kinetix-z-overlay,100)]"
                aria-hidden="true"
            />
        </Transition>

        <!-- Panel: v4 fade + zoom-95, 200ms. -->
        <Transition
            enter-active-class="animate-in fade-in-0 zoom-in-95 duration-200"
            leave-active-class="animate-out fade-out-0 zoom-out-95 duration-200"
        >
            <div
                v-if="open"
                class="inset-0 fixed z-[var(--kinetix-z-modal,100)] overflow-y-auto overscroll-contain"
                role="dialog"
                aria-modal="true"
                :aria-labelledby="headingId"
            >
                <!-- The WRAPPER is the shell's own scroll container, and
                     `min-h-full` on this row keeps a short panel centered. A
                     panel that outgrows the viewport (a long form that didn't
                     opt into `scrollBody`) scrolls here — its header and its
                     footer stay reachable instead of sitting off screen. -->
                <div
                    class="p-4 flex min-h-full items-center justify-center"
                    @click.self="close"
                >
                    <!-- `relative` matters: in the v4 registry the panel
                         itself is the fixed element, so the close button's
                         absolute top-4 right-4 anchors to it. Here the panel
                         sits inside a fixed flex wrapper — without relative,
                         the button anchors to the VIEWPORT and disappears. -->
                    <div
                        ref="panelEl"
                        tabindex="-1"
                        class="rounded-lg p-6 shadow-lg gap-4 relative grid w-full max-w-[calc(100%-2rem)] border bg-background outline-none"
                        :class="[
                            maxWidth,
                            scrollBody
                                ? 'max-h-[calc(100dvh-2rem)] grid-rows-[auto_minmax(0,1fr)_auto]'
                                : '',
                        ]"
                    >
                        <button
                            v-if="showCloseButton"
                            type="button"
                            class="top-4 right-4 rounded-xs [&_svg:not([class*='size-'])]:size-4 absolute opacity-70 ring-offset-background transition-opacity outline-none hover:opacity-100 focus:ring-2 focus:ring-ring focus:ring-offset-2 disabled:pointer-events-none [&_svg]:pointer-events-none [&_svg]:shrink-0"
                            :disabled="processing"
                            :aria-label="t('kinetix.close')"
                            @click="close"
                        >
                            <X />
                        </button>

                        <!-- Header: v4 stack (centered on mobile, left on ≥sm). -->
                        <slot name="header" :heading-id="headingId">
                            <div
                                v-if="title || description"
                                class="gap-2 sm:text-left flex flex-col text-center"
                            >
                                <h2
                                    v-if="title"
                                    :id="headingId"
                                    class="text-lg font-semibold leading-none"
                                >
                                    {{ title }}
                                </h2>
                                <p
                                    v-if="description"
                                    class="text-sm text-muted-foreground"
                                >
                                    {{ description }}
                                </p>
                            </div>
                        </slot>

                        <!-- `-mr-3 pr-3` parks the ScrollArea's overlay bar
                             in the panel's right padding instead of on top of
                             the fields (the reka viewport hides the native
                             scrollbar, so nothing reserves that gutter). -->
                        <ScrollArea
                            v-if="scrollBody"
                            type="auto"
                            class="-mr-3 min-h-0 pr-3"
                        >
                            <slot />
                        </ScrollArea>
                        <div v-else>
                            <slot />
                        </div>

                        <!-- Footer: v4 stack (column-reverse mobile, row right ≥sm). -->
                        <div
                            v-if="$slots.footer"
                            class="gap-2 sm:flex-row sm:justify-end flex flex-col-reverse"
                        >
                            <slot name="footer" />
                        </div>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
