<script setup lang="ts">
import { CircleAlert, CircleCheck, Info, TriangleAlert, X } from '@lucide/vue';
import type { Component } from 'vue';
import { computed, nextTick, ref, useId, useSlots, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixAnnounce } from '@/composables/useKinetixAnnounce';
import { useKinetixDismissal } from '@/composables/useKinetixDismissal';
import type { KinetixDismissMode } from '@/composables/useKinetixDismissal';
import { focusableNear } from '@/composables/useKinetixFocusTrap';
import { resolveIcon } from '@/composables/useKinetixIcons';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';
import {
    statusAlertClass,
    statusTextClass,
} from '@/composables/useKinetixStatusColor';
import type {
    KinetixAlertVariant,
    KinetixStatusColor,
} from '@/composables/useKinetixStatusColor';
import { useKinetixTransition } from '@/composables/useKinetixTransition';
import type { KinetixTransitionPreset } from '@/composables/useKinetixTransition';
import Alert from './primitives/Alert.vue';
import AlertDescription from './primitives/AlertDescription.vue';
import AlertTitle from './primitives/AlertTitle.vue';
import { cn } from './primitives/cn';

/**
 * An in-page alert: a status-colored surface with an icon, a title, a message
 * and optional actions — the one alert recipe every Kinetix notice builds on.
 *
 * Colors are the Kinetix status colors (`success` · `danger` · `warning` ·
 * `info` · `primary` · `gray`) on four surfaces (`soft` · `outline` ·
 * `accent` · `solid`). On the first three only the surface takes the color
 * and text stays on the foreground tokens; `solid` fills it and everything on
 * it takes the fill's own text color. Every pair reads at 4.5:1 in both
 * themes, and the status is never color alone — the icon and a
 * visually-hidden "Warning:"-style prefix carry it too.
 *
 * Closing it can last as long as you need (`dismissMode`): `hide` (this
 * mount), `session` (this tab), `device` (this browser, synced across tabs),
 * or `permanent` (the account: the Dismissals module, or your `persist`).
 * `dontShowAgain` offers both — the ✕ hides it for now, the link closes it for
 * good. `dismissDuration` brings a `session`/`device` close back after N ms.
 *
 * Motion is a named preset (`transition`), stilled by reduced motion. When a
 * focused alert goes away, focus moves to the next control instead of falling
 * to `<body>`, and the close is announced.
 */
const props = withDefaults(
    defineProps<{
        color?: KinetixStatusColor | null;
        variant?: KinetixAlertVariant;
        /** An icon name, a component, or `false` for none. Unset = per color. */
        icon?: string | Component | false | null;
        title?: string | null;
        description?: string | null;
        /** Render the title as `h2`…`h6`; unset = a plain paragraph. */
        headingLevel?: 2 | 3 | 4 | 5 | 6 | null;
        /**
         * `auto`: `danger` is an assertive `alert`, everything else a polite
         * `status`. `region` makes it a landmark (named by the title or
         * `label`); `none` drops the role.
         */
        role?: 'auto' | 'alert' | 'status' | 'region' | 'none';
        /** Accessible name when the title isn't enough (or is absent). */
        label?: string | null;
        /**
         * The visually-hidden status prefix read before the title ("Warning:").
         * Unset = the translated one for the color; `false` = none.
         */
        srLabel?: string | false | null;
        dismissible?: boolean;
        /** What closing does — see the component docs. */
        dismissMode?: KinetixDismissMode;
        /** Required to remember a close beyond `hide`. Unique per alert. */
        dismissKey?: string | null;
        /** `session`/`device`: ms until a closed alert comes back. */
        dismissDuration?: number | null;
        /**
         * `permanent`: store the close server-side; a rejection re-opens it.
         * Unset, the Dismissals module stores it when enabled.
         */
        persist?: ((key: string) => unknown | Promise<unknown>) | null;
        /** Add a "Don't show again" link next to the ✕ (permanent close). */
        dontShowAgain?: boolean;
        transition?: KinetixTransitionPreset;
        /** Animate the first render too (off: a page load shouldn't move). */
        appear?: boolean;
        class?: string;
    }>(),
    {
        color: 'gray',
        variant: 'soft',
        icon: undefined,
        title: null,
        description: null,
        headingLevel: null,
        role: 'auto',
        label: null,
        srLabel: undefined,
        dismissible: false,
        dismissMode: 'hide',
        dismissKey: null,
        dismissDuration: null,
        persist: null,
        dontShowAgain: false,
        transition: 'fade',
        appear: false,
    },
);

/** Controlled visibility; re-opening after a close also forgets that close. */
const open = defineModel<boolean>('open', { default: true });

const emit = defineEmits<{
    dismiss: [mode: KinetixDismissMode];
    dismissError: [error: unknown];
}>();

const slots = useSlots();
const { t } = useI18n();
const { announce } = useKinetixAnnounce();

const dismissal = useKinetixDismissal(() => props.dismissKey, {
    mode: () => props.dismissMode,
    duration: () => props.dismissDuration,
    // A property getter: read at close time, so a `persist` prop swapped after
    // mount still counts, and an absent one lets the Dismissals module step in.
    get persist() {
        return props.persist ?? null;
    },
});

/**
 * `permanent` needs somewhere to persist to — your `persist`, or the
 * Dismissals module. With neither, it is this browser (`device`).
 */
function effective(mode: KinetixDismissMode): KinetixDismissMode {
    return mode === 'permanent' && !dismissal.canPersist() ? 'device' : mode;
}

const transitionProps = useKinetixTransition(() => props.transition);
const visible = computed(() => open.value && !dismissal.dismissed.value);

const COLOR_ICONS: Record<string, Component> = {
    success: CircleCheck,
    danger: CircleAlert,
    warning: TriangleAlert,
};

const iconComponent = computed<Component | null>(() => {
    if (props.icon === false) {
        return null;
    }

    if (props.icon === undefined || props.icon === null) {
        return COLOR_ICONS[props.color ?? ''] ?? Info;
    }

    return typeof props.icon === 'string'
        ? resolveIcon(props.icon)
        : props.icon;
});

const SR_LABELS: Record<string, string> = {
    success: 'kinetix.alert_label_success',
    danger: 'kinetix.alert_label_danger',
    warning: 'kinetix.alert_label_warning',
    info: 'kinetix.alert_label_info',
};

const srLabelText = computed<string | null>(() => {
    if (props.srLabel === false) {
        return null;
    }

    if (props.srLabel) {
        return props.srLabel;
    }

    const key = SR_LABELS[props.color ?? ''];

    return key ? t(key) : null;
});

const titleId = `kinetix-alert-${useId()}`;
const hasTitle = computed(() => !!props.title || !!slots.title);
const hasBody = computed(() => !!props.description || !!slots.default);

const resolvedRole = computed<string | null>(() => {
    if (props.role === 'none') {
        return null;
    }

    if (props.role === 'auto') {
        return props.color === 'danger' ? 'alert' : 'status';
    }

    return props.role;
});

/** Only a landmark needs a name; live regions are read by their content. */
const labelledBy = computed(() =>
    resolvedRole.value === 'region' && !props.label && hasTitle.value
        ? titleId
        : undefined,
);
const ariaLabel = computed(() =>
    resolvedRole.value === 'region' ? (props.label ?? undefined) : undefined,
);

const headingTag = computed(() =>
    props.headingLevel ? `h${props.headingLevel}` : 'p',
);

/** A close that outlives the page reads "Dismiss"; a temporary one "Hide". */
function lasts(mode: KinetixDismissMode): boolean {
    const resolved = effective(mode);

    return (
        resolved === 'permanent' ||
        (resolved === 'device' && !props.dismissDuration)
    );
}

const closeLabel = computed(() =>
    t(
        lasts(props.dismissMode)
            ? 'kinetix.alert_dismiss'
            : 'kinetix.alert_hide',
    ),
);

const root = ref<HTMLElement | null>(null);

/** On a solid fill, everything inherits the fill's text color. */
const solid = computed(() => props.variant === 'solid');

async function close(mode: KinetixDismissMode): Promise<void> {
    const element = root.value;
    const focused =
        element !== null &&
        typeof document !== 'undefined' &&
        element.contains(document.activeElement);
    // Picked while the alert is still in the page — afterwards there is no
    // "next to it" left to measure from.
    const next = focused && element ? focusableNear(element) : null;

    const pending = dismissal.dismiss(effective(mode));
    open.value = false;

    await nextTick();
    next?.focus();
    announce(
        t(lasts(mode) ? 'kinetix.alert_dismissed' : 'kinetix.alert_hidden'),
    );

    try {
        await pending;
        emit('dismiss', effective(mode));
    } catch (error) {
        open.value = true;
        emit('dismissError', error);
    }
}

// The parent re-opening it is an explicit wish that beats a remembered close.
watch(open, (isOpen, wasOpen) => {
    if (isOpen && !wasOpen && dismissal.dismissed.value) {
        dismissal.restore();
    }
});
</script>

<template>
    <Transition v-bind="transitionProps" :appear="appear">
        <!-- `grid` + the `min-h-0` child is what lets the `collapse` preset
             animate the row height; every other preset ignores it. -->
        <div
            v-if="visible"
            ref="root"
            data-slot="kinetix-alert"
            :data-color="color ?? 'gray'"
            class="grid"
        >
            <div class="min-h-0">
                <Alert
                    :role="resolvedRole"
                    :aria-labelledby="labelledBy"
                    :aria-label="ariaLabel"
                    :class="
                        cn(
                            'gap-3 flex items-start',
                            statusAlertClass(color, variant),
                            props.class,
                        )
                    "
                >
                    <span
                        v-if="iconComponent || $slots.icon"
                        class="mt-0.5 shrink-0"
                        aria-hidden="true"
                    >
                        <slot name="icon">
                            <component
                                :is="iconComponent"
                                class="size-4"
                                :class="
                                    solid
                                        ? 'text-current'
                                        : statusTextClass(
                                              color,
                                              'text-muted-foreground',
                                          )
                                "
                            />
                        </slot>
                    </span>

                    <div class="min-w-0 flex-1">
                        <AlertTitle
                            v-if="hasTitle"
                            :id="titleId"
                            :as="headingTag"
                            class="mb-0 leading-snug"
                        >
                            <span v-if="srLabelText" class="sr-only">
                                {{ srLabelText }}
                            </span>
                            <slot name="title">{{ title }}</slot>
                        </AlertTitle>

                        <AlertDescription
                            v-if="hasBody"
                            :class="
                                cn(
                                    !solid && 'text-foreground/80',
                                    hasTitle && 'mt-1',
                                )
                            "
                        >
                            <span
                                v-if="srLabelText && !hasTitle"
                                class="sr-only"
                            >
                                {{ srLabelText }}
                            </span>
                            <slot>{{ description }}</slot>
                        </AlertDescription>

                        <div
                            v-if="
                                $slots.actions || (dismissible && dontShowAgain)
                            "
                            class="mt-3 gap-2 flex flex-wrap items-center"
                        >
                            <slot name="actions" />
                            <button
                                v-if="dismissible && dontShowAgain"
                                type="button"
                                :class="
                                    cn(
                                        buttonVariants({
                                            variant: 'ghost-current',
                                            size: 'sm',
                                        }),
                                        '-ml-2 underline underline-offset-4',
                                    )
                                "
                                @click="close('permanent')"
                            >
                                {{ t('kinetix.alert_dont_show_again') }}
                            </button>
                        </div>
                    </div>

                    <button
                        v-if="dismissible"
                        type="button"
                        :class="
                            buttonVariants({
                                variant: solid ? 'ghost-current' : 'ghost',
                                size: 'icon-sm',
                            })
                        "
                        class="-mt-1 -mr-1 shrink-0"
                        :aria-label="closeLabel"
                        @click="close(dismissMode)"
                    >
                        <X class="size-4" aria-hidden="true" />
                    </button>
                </Alert>
            </div>
        </div>
    </Transition>
</template>
