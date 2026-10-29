<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { CircleHelp } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type {
    ButtonSize,
    ButtonVariant,
} from '@/composables/useKinetixShadcnVariants';
import { useKinetixToursStore } from '@/stores/kinetixTours';
import KinetixButton from './KinetixButton.vue';

/**
 * "Show me around this page": replays the product tour declared for the
 * current page — seen or not, auto-start or manual-only (`->auto(false)`).
 * Renders NOTHING when the page has no tour, so it can sit in a layout's
 * header or help menu unconditionally.
 *
 *     <KinetixTourTrigger />                       <!-- labelled button -->
 *     <KinetixTourTrigger icon-only />             <!-- header icon -->
 *     <KinetixTourTrigger tour="setup-employees" /> <!-- a specific tour -->
 *
 * Inside a menu, take the default slot and render your own item:
 *
 *     <KinetixTourTrigger v-slot="{ start, label }">
 *         <DropdownMenuItem @select="start">{{ label }}</DropdownMenuItem>
 *     </KinetixTourTrigger>
 *
 * Needs the global `<KinetixTours />` host mounted, which runs the tour.
 */
defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        /** A tour id; by default the tour matching the current page. */
        tour?: string | null;
        label?: string | null;
        /** Just the icon (the label becomes its accessible name). */
        iconOnly?: boolean;
        variant?: ButtonVariant;
        size?: ButtonSize;
    }>(),
    {
        tour: null,
        label: null,
        iconOnly: false,
        variant: 'ghost',
        size: 'sm',
    },
);

const page = usePage();
const { t } = useI18n();
const store = useKinetixToursStore();

const target = computed(() => {
    if (props.tour) {
        return store.enabled ? store.find(props.tour) : null;
    }

    // page.url keeps the Inertia-reactive path (window.location isn't).
    return store.tourFor(
        String(page.component ?? ''),
        String(page.url ?? '').split(/[?#]/)[0],
    );
});

const text = computed(() => props.label ?? t('kinetix.tour_replay'));

function start(): void {
    if (target.value) {
        store.start(target.value.id);
    }
}
</script>

<template>
    <template v-if="target">
        <slot :start="start" :label="text" :tour="target">
            <KinetixButton
                v-bind="$attrs"
                :variant="variant"
                :size="iconOnly ? 'icon-sm' : size"
                :aria-label="iconOnly ? text : undefined"
                :title="iconOnly ? text : undefined"
                data-kinetix-tour-trigger
                @click="start"
            >
                <template #icon>
                    <CircleHelp aria-hidden="true" />
                </template>
                <span v-if="!iconOnly">{{ text }}</span>
            </KinetixButton>
        </slot>
    </template>
</template>
