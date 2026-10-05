import { afterEach, describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick, ref } from 'vue';

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));

import {
    kinetixTransition,
    useKinetixTransition,
} from '@/composables/useKinetixTransition';

describe('kinetixTransition presets', () => {
    it('maps each preset to its enter/leave classes', () => {
        expect(kinetixTransition('fade').enterFromClass).toBe('opacity-0');
        expect(kinetixTransition('slide-down').enterFromClass).toContain(
            '-translate-y-2',
        );
        expect(kinetixTransition('slide-up').leaveToClass).toContain(
            'translate-y-2',
        );
        expect(kinetixTransition('scale').enterFromClass).toContain('scale-95');
        expect(kinetixTransition('collapse').enterFromClass).toContain(
            'grid-rows-[0fr]',
        );
    });

    it('keeps every preset inside the 150–300ms micro-interaction band', () => {
        for (const preset of [
            'fade',
            'slide-down',
            'slide-up',
            'scale',
            'collapse',
        ]) {
            const { enterActiveClass, leaveActiveClass } =
                kinetixTransition(preset);

            expect(enterActiveClass).toMatch(/duration-(150|200|300)\b/);
            expect(leaveActiveClass).toMatch(/duration-(150|200|300)\b/);
        }
    });

    it('turns motion off for `none` and under reduced motion', () => {
        expect(kinetixTransition('none')).toEqual({ css: false });
        expect(kinetixTransition('slide-down', true)).toEqual({ css: false });
    });

    it('falls back to fade for an unknown preset', () => {
        expect(kinetixTransition('wobble')).toEqual(kinetixTransition('fade'));
    });
});

describe('useKinetixTransition', () => {
    afterEach(() => {
        document.documentElement.classList.remove('kx-reduce-motion');
    });

    it('follows the preset and the reduced-motion preference reactively', async () => {
        const preset = ref('scale');
        const scope = effectScope();
        const transition = scope.run(() => useKinetixTransition(preset))!;

        expect(transition.value.enterFromClass).toContain('scale-95');

        preset.value = 'fade';
        expect(transition.value.enterFromClass).toBe('opacity-0');

        document.documentElement.classList.add('kx-reduce-motion');
        await nextTick();
        await Promise.resolve();

        expect(transition.value).toEqual({ css: false });

        scope.stop();
    });
});
