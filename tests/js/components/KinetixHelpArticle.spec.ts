import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

const { routerMock, fetchMock } = vi.hoisted(() => ({
    routerMock: { visit: vi.fn() },
    fetchMock: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    router: routerMock,
    usePage: () => ({
        props: {
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
}));

vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));

import KinetixHelpArticle from '@/components/KinetixHelpArticle.vue';
import { clearKinetixHelpCache } from '@/composables/useKinetixHelp';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    messages: {
        en: {
            kinetix: {
                help_on_this_page: 'On this page',
                help_previous: 'Previous',
                help_next: 'Next',
                help_not_found: 'This article is not available.',
                help_translation_missing:
                    'This article has not been translated yet — showing the {language} version.',
                help_read_in: 'Read in',
                help_untranslated: 'Not translated yet',
            },
        },
    },
});

const DETAIL = {
    slug: '02-products',
    title: 'Products',
    group: 'Catalog',
    html: '<h1>Products</h1><h2>Creating</h2><p>Steps…</p><h2>Deleting</h2><p>More…</p><p><a href="/acme/help/01-dashboard">See dashboard</a></p>',
    locale: 'en',
    requestedLocale: 'en',
    isFallback: false,
    availableLocales: ['en'],
    prev: { slug: '01-dashboard', title: 'Dashboard' },
    next: { slug: '03-billing', title: 'Billing' },
};

const mountArticle = (props: Record<string, unknown> = {}) =>
    mount(KinetixHelpArticle, {
        props: { slug: '02-products', ...props },
        global: { plugins: [i18n] },
    });

// Every mounted article keeps a watcher on the app locale; without this a
// component left over from a previous test would react to the next test's
// language changes and consume its mocked responses.
enableAutoUnmount(afterEach);

describe('KinetixHelpArticle', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        clearKinetixHelpCache();
        i18n.global.locale.value = 'en';
        fetchMock.mockResolvedValue(DETAIL);
        window.history.replaceState({}, '', '/acme/help/02-products');
    });

    it('fetches the article and renders its html with a TOC', async () => {
        const w = mountArticle();
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            '/_kinetix/help/article/02-products?locale=en',
        );
        expect(w.html()).toContain('<h2 id="creating">Creating</h2>');
        expect(w.text()).toContain('On this page');
        expect(w.text()).toContain('Deleting');
    });

    it('renders prev/next linking through the index path', async () => {
        const w = mountArticle();
        await flushPromises();

        const prev = w
            .findAll('a')
            .find((a) => a.text().includes('Dashboard'))!;
        await prev.trigger('click');

        expect(routerMock.visit).toHaveBeenCalledWith(
            '/acme/help/01-dashboard',
        );
    });

    it('routes internal article-body links through Inertia', async () => {
        const w = mountArticle();
        await flushPromises();

        const link = w
            .findAll('article a')
            .find((a) => a.text() === 'See dashboard')!;
        await link.trigger('click');

        expect(routerMock.visit).toHaveBeenCalledWith(
            '/acme/help/01-dashboard',
        );
    });

    it('refetches when the slug prop changes', async () => {
        const w = mountArticle();
        await flushPromises();

        fetchMock.mockResolvedValueOnce({ ...DETAIL, slug: '03-billing' });
        await w.setProps({ slug: '03-billing' });
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            '/_kinetix/help/article/03-billing?locale=en',
        );
    });

    it('shows the not-found message when the fetch fails', async () => {
        fetchMock.mockRejectedValueOnce(new Error('404'));
        const w = mountArticle();
        await flushPromises();

        expect(w.text()).toContain('This article is not available.');
    });

    it('refetches in the new language when the app locale changes', async () => {
        const w = mountArticle();
        await flushPromises();

        fetchMock.mockResolvedValueOnce({
            ...DETAIL,
            title: 'Productos',
            html: '<h1>Productos</h1><h2>Configuración</h2>',
            locale: 'es',
            requestedLocale: 'es',
            availableLocales: ['en', 'es'],
        });

        i18n.global.locale.value = 'es';
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            '/_kinetix/help/article/02-products?locale=es',
        );
        expect(w.text()).toContain('Productos');
        // Headings differ per language: the TOC anchors are rebuilt, and a
        // non-ASCII heading keeps a meaningful id.
        expect(w.html()).toContain('id="configuracion"');
    });

    it('marks a fallback body with its real language and says so', async () => {
        fetchMock.mockResolvedValue({
            ...DETAIL,
            locale: 'en',
            requestedLocale: 'es',
            isFallback: true,
            availableLocales: ['en'],
        });
        i18n.global.locale.value = 'es';

        const w = mountArticle();
        await flushPromises();

        expect(w.find('article').attributes('lang')).toBe('en');
        expect(w.find('article').attributes('dir')).toBe('auto');
        expect(w.text()).toContain(
            'This article has not been translated yet — showing the English version.',
        );
    });

    it('lets the reader open another language without switching the app', async () => {
        fetchMock.mockResolvedValue({
            ...DETAIL,
            availableLocales: ['en', 'es'],
        });

        const w = mountArticle();
        await flushPromises();

        expect(w.text()).toContain('Read in');

        fetchMock.mockResolvedValueOnce({
            ...DETAIL,
            title: 'Productos',
            html: '<h1>Productos</h1>',
            locale: 'es',
            requestedLocale: 'es',
            availableLocales: ['en', 'es'],
        });

        const chip = w
            .findAll('button')
            .find((button) => button.text() === 'Español')!;
        await chip.trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            '/_kinetix/help/article/02-products?locale=es',
        );
        expect(w.find('article').attributes('lang')).toBe('es');
        // The app's own language is untouched — only this article switched.
        expect(i18n.global.locale.value).toBe('en');
    });

    it('hides the language chips when asked to', async () => {
        fetchMock.mockResolvedValue({
            ...DETAIL,
            availableLocales: ['en', 'es'],
        });

        const w = mountArticle({ hideLanguageSwitcher: true });
        await flushPromises();

        expect(w.text()).not.toContain('Read in');
    });
});
