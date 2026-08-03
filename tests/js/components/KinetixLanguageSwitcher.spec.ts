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

    it('renders a labelled select field in the select variant', () => {
        const w = mount(KinetixLanguageSwitcher, {
            props: { variant: 'select' },
            global: { plugins: [i18n] },
        });

        const label = w.find('label');
        expect(label.exists()).toBe(true);
        expect(label.text()).toBe('Language');

        // The label points at the field it labels.
        expect(label.attributes('for')).toBe(
            w.find('[role="combobox"]').attributes('id'),
        );
    });

    it('hides the visible label but keeps it accessible when asked', () => {
        const w = mount(KinetixLanguageSwitcher, {
            props: { variant: 'select', showLabel: false },
            global: { plugins: [i18n] },
        });

        expect(w.find('label').exists()).toBe(false);
        expect(w.find('[role="combobox"]').attributes('aria-label')).toBe(
            'Language',
        );
    });

    it('accepts a custom label for the select variant', () => {
        const w = mount(KinetixLanguageSwitcher, {
            props: { variant: 'select', label: 'Idioma' },
            global: { plugins: [i18n] },
        });

        expect(w.find('label').text()).toBe('Idioma');
    });

    it('defaults to the dropdown variant', () => {
        const w = mount(KinetixLanguageSwitcher, {
            global: { plugins: [i18n] },
        });

        expect(w.find('label').exists()).toBe(false);
        expect(w.find('button').attributes('aria-label')).toBe('Language');
    });

    it('keeps two switchers on the same page in agreement', async () => {
        // A header dropdown plus a settings select is the whole point of the
        // variants, so they must not drift apart: a per-instance `current` ref
        // left the second one showing the previous locale until a full reload.
        fetchMock.mockResolvedValueOnce({ locale: 'es' });

        const dropdown = mount(KinetixLanguageSwitcher, {
            props: { showLabel: true },
            global: { plugins: [i18n] },
        });
        const select = mount(KinetixLanguageSwitcher, {
            props: { variant: 'select' },
            global: { plugins: [i18n] },
        });

        expect(dropdown.text()).toContain('EN');

        await select
            .findComponent({ name: 'KinetixSelect' })
            .vm.$emit('update:value', 'es');
        await flushPromises();

        expect(i18n.global.locale.value).toBe('es');
        expect(dropdown.text()).toContain('ES');
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
