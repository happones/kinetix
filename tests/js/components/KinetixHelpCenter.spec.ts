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

import KinetixHelpCenter from '@/components/KinetixHelpCenter.vue';
import { clearKinetixHelpCache } from '@/composables/useKinetixHelp';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    messages: {
        en: {
            kinetix: {
                help_search_placeholder: 'Search the manual…',
                help_no_results: 'No articles match "{query}".',
                help_no_articles: 'No help articles yet.',
                help_grid_view: 'Card view',
                help_list_view: 'List view',
                help_untranslated: 'Not translated yet',
            },
        },
    },
});

const ARTICLES = [
    {
        slug: '01-dashboard',
        title: 'Dashboard',
        group: 'Basics',
        icon: null,
        excerpt: 'Widgets at a glance.',
        locale: 'en',
        isFallback: false,
    },
    {
        slug: '02-products',
        title: 'Products',
        group: 'Catalog',
        icon: null,
        excerpt: 'Manage products.',
        locale: 'en',
        isFallback: false,
    },
];

const mountCenter = (props: Record<string, unknown> = {}) =>
    mount(KinetixHelpCenter, { props, global: { plugins: [i18n] } });

// Each mounted center watches the app locale; unmount between tests so a stale
// instance can't answer the next test's language change.
enableAutoUnmount(afterEach);

describe('KinetixHelpCenter', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.useRealTimers();
        clearKinetixHelpCache();
        i18n.global.locale.value = 'en';
        fetchMock.mockResolvedValue({ articles: ARTICLES });
    });

    it('loads articles on mount and renders grouped cards', async () => {
        const w = mountCenter();
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/_kinetix/help?locale=en');
        expect(w.text()).toContain('Basics');
        expect(w.text()).toContain('Catalog');
        expect(w.text()).toContain('Dashboard');
        expect(w.text()).toContain('Widgets at a glance.');
    });

    it('toggles between grid and list layouts', async () => {
        const w = mountCenter();
        await flushPromises();

        expect(w.find('.grid').exists()).toBe(true);

        await w.get('[title="List view"]').trigger('click');
        expect(w.find('.divide-y').exists()).toBe(true);
    });

    it('navigates using the current path as the article base', async () => {
        window.history.replaceState({}, '', '/acme/help');
        const w = mountCenter();
        await flushPromises();

        await w.get('a').trigger('click');
        expect(routerMock.visit).toHaveBeenCalledWith(
            '/acme/help/01-dashboard',
        );
    });

    it('honors a custom article-href builder', async () => {
        const w = mountCenter({
            articleHref: (slug: string) => `/custom/${slug}`,
        });
        await flushPromises();

        await w.get('a').trigger('click');
        expect(routerMock.visit).toHaveBeenCalledWith('/custom/01-dashboard');
    });

    it('debounces the search and renders server results', async () => {
        vi.useFakeTimers();
        const w = mountCenter();
        await flushPromises();

        fetchMock.mockResolvedValueOnce({
            results: [
                {
                    slug: '02-products',
                    title: 'Products',
                    group: 'Catalog',
                    excerpt: '…drag and drop…',
                    locale: 'en',
                    isFallback: false,
                },
            ],
        });

        await w.get('input').setValue('drag');
        await w.get('input').trigger('input');
        expect(fetchMock).toHaveBeenCalledTimes(1); // only the initial load so far

        vi.advanceTimersByTime(300);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            '/_kinetix/help/search?q=drag&locale=en',
        );
        expect(w.text()).toContain('…drag and drop…');
        vi.useRealTimers();
    });

    it('shows the empty state without articles', async () => {
        fetchMock.mockResolvedValue({ articles: [] });
        const w = mountCenter();
        await flushPromises();

        expect(w.text()).toContain('No help articles yet.');
    });

    it('reloads the index when the app language changes', async () => {
        const w = mountCenter();
        await flushPromises();

        fetchMock.mockResolvedValueOnce({
            articles: [
                {
                    ...ARTICLES[0],
                    title: 'Panel',
                    excerpt: 'Widgets de un vistazo.',
                    locale: 'es',
                },
            ],
        });

        i18n.global.locale.value = 'es';
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/_kinetix/help?locale=es');
        expect(w.text()).toContain('Panel');
        expect(w.text()).not.toContain('Dashboard');
    });

    it('serves a language it already loaded from cache', async () => {
        const w = mountCenter();
        await flushPromises();

        fetchMock.mockResolvedValueOnce({ articles: [] });
        i18n.global.locale.value = 'es';
        await flushPromises();

        const callsAfterSwitch = fetchMock.mock.calls.length;

        i18n.global.locale.value = 'en';
        await flushPromises();

        expect(fetchMock.mock.calls.length).toBe(callsAfterSwitch);
        expect(w.text()).toContain('Dashboard');
    });

    it('marks entries that fall back to another language', async () => {
        fetchMock.mockResolvedValue({
            articles: [{ ...ARTICLES[0], locale: 'en', isFallback: true }],
        });
        i18n.global.locale.value = 'es';

        const w = mountCenter();
        await flushPromises();

        expect(w.text()).toContain('EN');
        expect(w.get('[lang="en"]').text()).toContain('Dashboard');
    });
});
