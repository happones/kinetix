import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import {
    useKinetixChartPalette,
    useKinetixChartSurfaceVars,
} from '@/composables/useKinetixChartPalette';

const Probe = defineComponent({
    setup() {
        const palette = useKinetixChartPalette();

        return () => h('div', palette.value.join(','));
    },
});

const SurfaceProbe = defineComponent({
    setup() {
        const vars = useKinetixChartSurfaceVars();

        return () => h('div', JSON.stringify(vars.value));
    },
});

const mockTokens = (tokens: Record<string, string>) =>
    vi.spyOn(window, 'getComputedStyle').mockReturnValue({
        getPropertyValue: (name: string) => tokens[name] ?? '',
    } as unknown as CSSStyleDeclaration);

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
        const spy = mockTokens({
            '--chart-1': '221.2 83.2% 53.3%',
            '--chart-2': 'oklch(0.6 0.15 160)',
        });

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

describe('useKinetixChartSurfaceVars', () => {
    it('passes complete-color tokens through unwrapped (starter-kit shape)', async () => {
        const spy = mockTokens({
            '--border': 'hsl(0 0% 92.8%)',
            '--muted-foreground': 'oklch(0.552 0.016 285.938)',
        });

        const w = mount(SurfaceProbe);
        await w.vm.$nextTick();
        const vars = JSON.parse(w.text());

        // No double hsl(hsl(…)) — the v0.131.0 regression.
        expect(vars['--vis-axis-grid-color']).toBe('hsl(0 0% 92.8%)');
        expect(vars['--vis-axis-tick-label-color']).toBe(
            'oklch(0.552 0.016 285.938)',
        );

        spy.mockRestore();
    });

    it('wraps HSL-triplet tokens (kinetix.css shape)', async () => {
        const spy = mockTokens({
            '--border': '240 5.9% 90%',
            '--card': '0 0% 100%',
        });

        const w = mount(SurfaceProbe);
        await w.vm.$nextTick();
        const vars = JSON.parse(w.text());

        expect(vars['--vis-axis-grid-color']).toBe('hsl(240 5.9% 90%)');
        expect(vars['--vis-donut-segment-stroke-color']).toBe('hsl(0 0% 100%)');

        spy.mockRestore();
    });

    it('falls back per theme when a token is undefined', async () => {
        const spy = mockTokens({});

        const light = mount(SurfaceProbe);
        await light.vm.$nextTick();
        expect(JSON.parse(light.text())['--vis-axis-grid-color']).toBe(
            'hsl(240 5.9% 90%)',
        );

        document.documentElement.classList.add('dark');
        const dark = mount(SurfaceProbe);
        await dark.vm.$nextTick();
        expect(JSON.parse(dark.text())['--vis-axis-grid-color']).toBe(
            'hsl(240 3.7% 15.9%)',
        );

        spy.mockRestore();
    });
});
