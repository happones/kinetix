import { mount } from '@vue/test-utils';
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
    it('renders an icon trigger', () => {
        const w = mount(KinetixModeToggle, opts);
        expect(w.find('button').exists()).toBe(true);
        expect(w.findAll('svg').length).toBeGreaterThanOrEqual(2); // Sun + Moon
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
    it('renders an accessibility trigger button', () => {
        const w = mount(KinetixAccessibilityMenu, opts);
        expect(w.find('button').exists()).toBe(true);
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
