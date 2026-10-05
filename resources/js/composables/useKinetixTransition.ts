import { computed, toValue } from 'vue';
import type { ComputedRef, MaybeRefOrGetter } from 'vue';
import { useKinetixReducedMotion } from '@/composables/useKinetixReducedMotion';

/**
 * Named enter/leave presets for `<Transition>`, so an alert, a banner or any
 * other surface that appears and goes away picks a motion by name instead of
 * re-typing class strings:
 *
 *     const transition = useKinetixTransition(() => props.transition);
 *     <Transition v-bind="transition"> … </Transition>
 *
 * - `fade`       — opacity only (the default everywhere).
 * - `slide-down` — fades in from slightly above, leaves the same way.
 * - `slide-up`   — the same from slightly below.
 * - `scale`      — fades in from 95%.
 * - `collapse`   — grows from zero height and shrinks back, so the content
 *                  under it glides instead of jumping. It animates the ROW of
 *                  a grid, so the transitioned element must be `grid` with a
 *                  `min-h-0` child (KinetixAlert renders exactly that).
 * - `none`       — no motion.
 *
 * Every preset collapses to `none` under reduced motion (OS setting, user
 * preference or `kinetix.motion = 'reduced'`); `css: false` lets Vue swap the
 * element instantly instead of waiting on a duration that will never run.
 *
 * Class strings are static so Tailwind's scanner keeps them.
 */
export type KinetixTransitionPreset =
    | 'fade'
    | 'slide-down'
    | 'slide-up'
    | 'scale'
    | 'collapse'
    | 'none';

export interface KinetixTransitionProps {
    css?: boolean;
    enterActiveClass?: string;
    enterFromClass?: string;
    enterToClass?: string;
    leaveActiveClass?: string;
    leaveFromClass?: string;
    leaveToClass?: string;
}

const PRESETS: Record<
    Exclude<KinetixTransitionPreset, 'none'>,
    KinetixTransitionProps
> = {
    fade: {
        enterActiveClass: 'transition-opacity duration-200 ease-out',
        enterFromClass: 'opacity-0',
        leaveActiveClass: 'transition-opacity duration-150 ease-in',
        leaveToClass: 'opacity-0',
    },
    'slide-down': {
        enterActiveClass:
            'transition-[opacity,translate] duration-200 ease-out',
        enterFromClass: 'opacity-0 -translate-y-2',
        leaveActiveClass: 'transition-[opacity,translate] duration-150 ease-in',
        leaveToClass: 'opacity-0 -translate-y-2',
    },
    'slide-up': {
        enterActiveClass:
            'transition-[opacity,translate] duration-200 ease-out',
        enterFromClass: 'opacity-0 translate-y-2',
        leaveActiveClass: 'transition-[opacity,translate] duration-150 ease-in',
        leaveToClass: 'opacity-0 translate-y-2',
    },
    scale: {
        enterActiveClass: 'transition-[opacity,scale] duration-200 ease-out',
        enterFromClass: 'opacity-0 scale-95',
        leaveActiveClass: 'transition-[opacity,scale] duration-150 ease-in',
        leaveToClass: 'opacity-0 scale-95',
    },
    collapse: {
        // `overflow-hidden` only while moving: it would clip focus rings and
        // shadows the rest of the time.
        enterActiveClass:
            'overflow-hidden transition-[grid-template-rows,opacity] duration-200 ease-out',
        enterFromClass: 'grid-rows-[0fr] opacity-0',
        enterToClass: 'grid-rows-[1fr]',
        leaveActiveClass:
            'overflow-hidden transition-[grid-template-rows,opacity] duration-150 ease-in',
        leaveFromClass: 'grid-rows-[1fr]',
        leaveToClass: 'grid-rows-[0fr] opacity-0',
    },
};

const STILL: KinetixTransitionProps = { css: false };

/**
 * The `<Transition>` props for a preset. Unknown names fall back to `fade`, so
 * a typo in a host template degrades to the default instead of to nothing.
 */
export function kinetixTransition(
    preset: KinetixTransitionPreset | string | null | undefined,
    reduced = false,
): KinetixTransitionProps {
    if (reduced || preset === 'none') {
        return STILL;
    }

    return PRESETS[preset as keyof typeof PRESETS] ?? PRESETS.fade;
}

/** Reactive `kinetixTransition()` that also follows reduced motion. */
export function useKinetixTransition(
    preset: MaybeRefOrGetter<
        KinetixTransitionPreset | string | null | undefined
    >,
): ComputedRef<KinetixTransitionProps> {
    const reduced = useKinetixReducedMotion();

    return computed(() => kinetixTransition(toValue(preset), reduced.value));
}
