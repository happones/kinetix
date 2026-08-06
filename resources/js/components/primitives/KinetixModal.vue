<script setup lang="ts">
import { X } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixFocusTrap } from '@/composables/useKinetixFocusTrap';

/**
 * The shared modal shell, styled after shadcn-vue's new-york-v4 Dialog so
 * every Kinetix modal carries the same design line: `bg-black/80` overlay and
 * panel animating with `fade + zoom-95` over 200ms, `bg-background` panel
 * (rounded-lg border shadow-lg), the v4 close button, and the v4
 * header/footer stacks. Deviations from the registry are deliberate:
 * z-index uses the Kinetix layer scale (never a raw utility), and focus
 * trapping/escape/aria ride on useKinetixFocusTrap.
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
        /** Scroll the BODY slot instead of growing past the viewport. */
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
                class="inset-0 p-4 fixed z-[var(--kinetix-z-modal,100)] flex items-center justify-center"
                role="dialog"
                aria-modal="true"
                :aria-labelledby="headingId"
                @click.self="close"
            >
                <div
                    ref="panelEl"
                    tabindex="-1"
                    class="rounded-lg p-6 shadow-lg gap-4 grid w-full max-w-[calc(100%-2rem)] border bg-background outline-none"
                    :class="[
                        maxWidth,
                        scrollBody
                            ? 'max-h-[90vh] grid-rows-[auto_minmax(0,1fr)_auto]'
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

                    <div :class="scrollBody ? 'min-h-0 overflow-y-auto' : ''">
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
        </Transition>
    </Teleport>
</template>
