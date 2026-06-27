import { flushPromises, mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

const reloadMock = vi.fn();
const pageProps = {
    kinetix_config: { route_prefix: '_kinetix' },
    kinetix_locale: {
        enabled: true,
        current: 'en',
        locales: [
            { code: 'en', label: 'English' },
            { code: 'es', label: 'Español' },
        ],
    },
};

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: pageProps }),
    router: { reload: (...a: unknown[]) => reloadMock(...a) },
}));

const fetchMock = vi.fn();
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));

import KinetixLanguageSwitcher from '@/components/KinetixLanguageSwitcher.vue';
import { useKinetixLocale } from '@/composables/useKinetixLocale';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    missing: (_l: string, key: string) => key,
    messages: { en: { kinetix: { language: 'Language' } } },
});

beforeEach(() => {
    reloadMock.mockReset();
    fetchMock.mockReset();
    i18n.global.locale.value = 'en';
});

describe('KinetixLanguageSwitcher', () => {
    it('renders an accessible trigger', () => {
        const w = mount(KinetixLanguageSwitcher, {
            global: { plugins: [i18n] },
        });
        expect(w.find('button').attributes('aria-label')).toBe('Language');
    });

    it('shows the active locale code when showLabel is set', () => {
        const w = mount(KinetixLanguageSwitcher, {
            props: { showLabel: true },
            global: { plugins: [i18n] },
        });
        expect(w.text()).toContain('EN');
    });
});

const Harness = defineComponent({
    setup(_, { expose }) {
        const api = useKinetixLocale();
        expose(api);
        return () => h('div');
    },
});

const mountComposable = () => mount(Harness, { global: { plugins: [i18n] } });

describe('useKinetixLocale', () => {
    it('persists the choice and flips the SPA locale', async () => {
        fetchMock.mockResolvedValueOnce({ locale: 'es' });
        const w = mountComposable();

        await (w.vm as any).setLocale('es');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/_kinetix/locale', {
            method: 'POST',
            body: { locale: 'es' },
        });
        expect(i18n.global.locale.value).toBe('es');
        expect(reloadMock).toHaveBeenCalled();
    });

    it('no-ops when selecting the current locale', async () => {
        const w = mountComposable();

        await (w.vm as any).setLocale('en');

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('rolls back the optimistic switch on failure', async () => {
        fetchMock.mockRejectedValueOnce(new Error('nope'));
        const w = mountComposable();

        await expect((w.vm as any).setLocale('es')).rejects.toThrow();
        expect(i18n.global.locale.value).toBe('en');
        expect(reloadMock).not.toHaveBeenCalled();
    });
});
