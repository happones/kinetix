import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { useKinetixChartPalette } from '@/composables/useKinetixChartPalette';

const Probe = defineComponent({
    setup() {
        const palette = useKinetixChartPalette();

        return () => h('div', palette.value.join(','));
    },
});

afterEach(() => {
    document.documentElement.classList.remove('dark');
    document.documentElement.style.removeProperty('--chart-1');
});

describe('useKinetixChartPalette', () => {
    it('falls back to the validated light palette when no tokens exist', () => {
        const w = mount(Probe);

        expect(w.text()).toContain('#2563eb');
        expect(w.text().split(',')).toHaveLength(8);
    });

    it('switches to the dark fallback when html.dark is set', async () => {
        document.documentElement.classList.add('dark');

        const w = mount(Probe);
        // The palette resolves in onMounted; the DOM catches up on nextTick.
        await w.vm.$nextTick();

        expect(w.text()).toContain('#3b82f6');
        expect(w.text()).not.toContain('#2563eb');
    });

    it('updates live when dark mode toggles', async () => {
        const w = mount(Probe);
        expect(w.text()).toContain('#2563eb');

        document.documentElement.classList.add('dark');
        // MutationObserver delivers as a microtask.
        await vi.waitFor(() => {
            expect(w.text()).toContain('#3b82f6');
        });
    });

    it('wraps HSL triplet tokens and passes complete colors through', async () => {
        const styles = {
            getPropertyValue: (name: string) => {
                if (name === '--chart-1') {
                    return '221.2 83.2% 53.3%';
                }

                if (name === '--chart-2') {
                    return 'oklch(0.6 0.15 160)';
                }

                return '';
            },
        };
        const spy = vi
            .spyOn(window, 'getComputedStyle')
            .mockReturnValue(styles as unknown as CSSStyleDeclaration);

        const w = mount(Probe);
        await w.vm.$nextTick();
        const colors = w.text().split(',');

        expect(colors[0]).toBe('hsl(221.2 83.2% 53.3%)');
        expect(colors[1]).toBe('oklch(0.6 0.15 160)');
        // Undefined slots fall back per-slot.
        expect(colors[2]).toBe('#d97706');

        spy.mockRestore();
    });
});
