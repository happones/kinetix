import { flushPromises, mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n, useI18n } from 'vue-i18n';

const reloadMock = vi.fn();
const fetchMock = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: {
            kinetix_config: { route_prefix: '_kinetix' },
            kinetix_locale: {
                enabled: true,
                current: 'en',
                locales: [
                    { code: 'en', label: 'English' },
                    { code: 'es', label: 'Español' },
                ],
            },
        },
    }),
    router: { reload: (...a: unknown[]) => reloadMock(...a) },
}));

vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));

import { useKinetixLocale } from '@/composables/useKinetixLocale';
import {
    attachKinetixI18n,
    kinetixLoadedMessages,
    loadKinetixLocale,
    registerKinetixLocales,
} from '@/plugins/kinetixLocales';

const messages = {
    en: { kinetix: { language: 'Language' } },
    es: { kinetix: { language: 'Idioma' } },
};

type Api = ReturnType<typeof useKinetixLocale> & { label: () => string };

/** Mounts the composable plus a probe that renders the translated label. */
function mountWith(i18n: ReturnType<typeof createI18n>) {
    const Harness = defineComponent({
        setup(_, { expose }) {
            const api = useKinetixLocale();
            const { t } = useI18n();
            expose({ ...api, label: () => t('kinetix.language') });

            return () => h('span', t('kinetix.language'));
        },
    });

    const wrapper = mount(Harness, { global: { plugins: [i18n] } });

    return { wrapper, api: wrapper.vm as unknown as Api };
}

function eagerI18n() {
    return createI18n({
        legacy: false,
        locale: 'en',
        fallbackLocale: 'en',
        messages,
    });
}

beforeEach(() => {
    reloadMock.mockReset();
    fetchMock.mockReset();
    fetchMock.mockResolvedValue({ locale: 'es' });
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('useKinetixLocale with lazy locales', () => {
    let release: () => void;
    let failNext: boolean;

    beforeEach(async () => {
        failNext = false;
        release = () => {};
        registerKinetixLocales({
            fallbackLocale: 'en',
            loader: async (locale) => {
                if (locale !== 'en') {
                    await new Promise<void>((resolve) => {
                        release = resolve;
                    });

                    if (failNext) {
                        throw new Error('chunk failed');
                    }
                }

                return messages[locale as 'en' | 'es'];
            },
        });
        await loadKinetixLocale('en');
    });

    function lazyI18n() {
        const i18n = createI18n({
            legacy: false,
            locale: 'en',
            fallbackLocale: 'en',
            messages: kinetixLoadedMessages(),
        });
        attachKinetixI18n(i18n);

        return i18n;
    }

    it('keeps the old locale while the messages load, flips once they are in, then persists', async () => {
        const i18n = lazyI18n();
        const { wrapper, api } = mountWith(i18n);

        const switching = api.setLocale('es');
        await flushPromises();

        // Still English, and nothing sent yet: no raw keys, no early POST.
        expect(i18n.global.locale.value).toBe('en');
        expect(api.saving).toBe(true);
        expect(wrapper.text()).toBe('Language');
        expect(fetchMock).not.toHaveBeenCalled();

        release();
        await switching;
        await flushPromises();

        expect(i18n.global.locale.value).toBe('es');
        expect(wrapper.text()).toBe('Idioma');
        expect(fetchMock).toHaveBeenCalledWith('/_kinetix/locale', {
            method: 'POST',
            body: { locale: 'es' },
        });
        expect(reloadMock).toHaveBeenCalled();
        expect(api.saving).toBe(false);
    });

    it('lets switching back to the current locale cancel a pick still loading', async () => {
        const i18n = lazyI18n();
        const { wrapper, api } = mountWith(i18n);

        const slow = api.setLocale('es');
        await flushPromises();
        expect(api.saving).toBe(true);

        // Back to English (the current locale) before Spanish arrives.
        await api.setLocale('en');
        expect(api.saving).toBe(false);

        release();
        await slow;
        await flushPromises();

        expect(i18n.global.locale.value).toBe('en');
        expect(wrapper.text()).toBe('Language');
        expect(api.saving).toBe(false);
        expect(fetchMock).not.toHaveBeenCalled();
        expect(reloadMock).not.toHaveBeenCalled();
    });

    it('lets a pick on another switcher supersede one still loading', async () => {
        const i18n = lazyI18n();
        const header = mountWith(i18n);
        const settings = mountWith(i18n);

        const slow = header.api.setLocale('es');
        await flushPromises();

        await settings.api.setLocale('en');

        release();
        await slow;
        await flushPromises();

        expect(i18n.global.locale.value).toBe('en');
        expect(header.api.saving).toBe(false);
        expect(settings.api.saving).toBe(false);
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('throws, keeps the locale and sends nothing when the messages fail to load', async () => {
        vi.spyOn(console, 'warn').mockImplementation(() => {});
        failNext = true;
        const i18n = lazyI18n();
        const { wrapper, api } = mountWith(i18n);

        const switching = api.setLocale('es');
        await flushPromises();
        release();

        await expect(switching).rejects.toThrow('"es"');
        expect(i18n.global.locale.value).toBe('en');
        expect(wrapper.text()).toBe('Language');
        expect(api.saving).toBe(false);
        expect(fetchMock).not.toHaveBeenCalled();
        expect(reloadMock).not.toHaveBeenCalled();
    });
});

describe('useKinetixLocale in eager mode', () => {
    beforeEach(async () => {
        vi.resetModules();
    });

    it('flips at once, persists and reloads, exactly as before', async () => {
        const { useKinetixLocale: eagerLocale } =
            await import('@/composables/useKinetixLocale');
        const { kinetixLocalesEnabled } =
            await import('@/plugins/kinetixLocales');
        expect(kinetixLocalesEnabled()).toBe(false);

        const i18n = eagerI18n();
        const Harness = defineComponent({
            setup(_, { expose }) {
                expose(eagerLocale());

                return () => h('div');
            },
        });
        const api = mount(Harness, { global: { plugins: [i18n] } })
            .vm as unknown as ReturnType<typeof useKinetixLocale>;

        const switching = api.setLocale('es');
        // Eager: the flip doesn't wait for a download.
        await Promise.resolve();
        await Promise.resolve();
        expect(i18n.global.locale.value).toBe('es');

        await switching;
        expect(fetchMock).toHaveBeenCalledWith('/_kinetix/locale', {
            method: 'POST',
            body: { locale: 'es' },
        });
        expect(reloadMock).toHaveBeenCalled();
    });

    it('rolls back when persisting fails', async () => {
        const { useKinetixLocale: eagerLocale } =
            await import('@/composables/useKinetixLocale');
        fetchMock.mockReset();
        fetchMock.mockRejectedValueOnce(new Error('nope'));

        const i18n = eagerI18n();
        const Harness = defineComponent({
            setup(_, { expose }) {
                expose(eagerLocale());

                return () => h('div');
            },
        });
        const api = mount(Harness, { global: { plugins: [i18n] } })
            .vm as unknown as ReturnType<typeof useKinetixLocale>;

        await expect(api.setLocale('es')).rejects.toThrow('nope');
        expect(i18n.global.locale.value).toBe('en');
        expect(api.saving).toBe(false);
        expect(reloadMock).not.toHaveBeenCalled();
    });
});
