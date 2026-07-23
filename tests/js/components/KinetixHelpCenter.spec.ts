import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

const { routerMock, fetchMock } = vi.hoisted(() => ({
    routerMock: { visit: vi.fn() },
    fetchMock: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    router: routerMock,
    usePage: () => ({ props: {} }),
}));

vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));

import KinetixHelpCenter from '@/components/KinetixHelpCenter.vue';

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
    },
    {
        slug: '02-products',
        title: 'Products',
        group: 'Catalog',
        icon: null,
        excerpt: 'Manage products.',
    },
];

const mountCenter = (props: Record<string, unknown> = {}) =>
    mount(KinetixHelpCenter, { props, global: { plugins: [i18n] } });

describe('KinetixHelpCenter', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.useRealTimers();
        fetchMock.mockResolvedValue({ articles: ARTICLES });
    });

    it('loads articles on mount and renders grouped cards', async () => {
        const w = mountCenter();
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/_kinetix/help');
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
                },
            ],
        });

        await w.get('input').setValue('drag');
        await w.get('input').trigger('input');
        expect(fetchMock).toHaveBeenCalledTimes(1); // only the initial load so far

        vi.advanceTimersByTime(300);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/_kinetix/help/search?q=drag');
        expect(w.text()).toContain('…drag and drop…');
        vi.useRealTimers();
    });

    it('shows the empty state without articles', async () => {
        fetchMock.mockResolvedValue({ articles: [] });
        const w = mountCenter();
        await flushPromises();

        expect(w.text()).toContain('No help articles yet.');
    });
});
