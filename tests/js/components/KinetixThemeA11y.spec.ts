import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));
const fetchMock = vi.fn().mockResolvedValue({});
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));

import KinetixModeToggle from '@/components/KinetixModeToggle.vue';
import KinetixAccessibilityMenu from '@/components/KinetixAccessibilityMenu.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    missing: (_l: string, key: string) => key,
    messages: { en: { kinetix: {} } },
});
const opts = { global: { plugins: [i18n] } };

beforeEach(() => {
    localStorage.clear();
    document.documentElement.className = '';
});
afterEach(() => vi.clearAllMocks());

describe('KinetixModeToggle', () => {
    it('has a labelled trigger whose Dark item flips html.dark and the stored appearance', async () => {
        const w = mount(KinetixModeToggle, {
            ...opts,
            attachTo: document.body,
        });
        const trigger = w.get('button');
        expect(trigger.attributes('aria-label')).toBe('kinetix.toggle_theme');

        await trigger.trigger('pointerdown', {
            button: 0,
            pointerType: 'mouse',
        });
        await trigger.trigger('click');
        await flushPromises();

        const dark = Array.from(
            document.body.querySelectorAll<HTMLElement>('[role="menuitem"]'),
        ).find((el) => el.textContent?.includes('appearance_dark'));
        expect(dark).toBeDefined();

        dark!.click();
        await flushPromises();

        expect(localStorage.getItem('appearance')).toBe('dark');
        expect(document.documentElement.classList.contains('dark')).toBe(true);

        w.unmount();
    });

    it("setAppearance persists to the starter-kit 'appearance' storage and toggles html.dark", async () => {
        const { useKinetixAppearance } =
            await import('@/composables/useKinetixAppearance');
        const { setAppearance } = useKinetixAppearance();
        setAppearance('dark');
        expect(localStorage.getItem('appearance')).toBe('dark');
        expect(document.documentElement.classList.contains('dark')).toBe(true);

        setAppearance('light');
        expect(document.documentElement.classList.contains('dark')).toBe(false);
    });
});

describe('KinetixAccessibilityMenu', () => {
    it('has a labelled trigger that opens the preference toggles, and a toggle sets the preference', async () => {
        const w = mount(KinetixAccessibilityMenu, {
            ...opts,
            attachTo: document.body,
        });
        const trigger = w.get('button');
        expect(trigger.attributes('aria-label')).toBe('kinetix.a11y_title');

        await trigger.trigger('click');
        await flushPromises();

        const labels = Array.from(document.body.querySelectorAll('label')).map(
            (el) => el.textContent?.trim(),
        );
        expect(labels).toEqual(
            expect.arrayContaining([
                'kinetix.a11y_reduced_motion',
                'kinetix.a11y_high_contrast',
                'kinetix.a11y_underline_links',
                'kinetix.a11y_enhanced_focus',
            ]),
        );

        const highContrast = Array.from(
            document.body.querySelectorAll('label'),
        ).find((el) => el.textContent?.includes('a11y_high_contrast'));
        highContrast!.querySelector<HTMLElement>('[role="checkbox"]')!.click();
        await flushPromises();

        expect(
            document.documentElement.classList.contains('kx-high-contrast'),
        ).toBe(true);

        w.unmount();
    });

    it('keeps preferences client-side even when the server persist fails (guest)', async () => {
        fetchMock.mockRejectedValueOnce(new Error('401'));
        const { useKinetixAccessibility } =
            await import('@/composables/useKinetixAccessibility');
        const { set, prefs } = useKinetixAccessibility();
        // Should not throw despite the rejected server call.
        await set('highContrast', true);
        expect(prefs.highContrast).toBe(true);
        expect(
            document.documentElement.classList.contains('kx-high-contrast'),
        ).toBe(true);
    });
});
