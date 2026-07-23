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

import KinetixHelpArticle from '@/components/KinetixHelpArticle.vue';

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
            },
        },
    },
});

const DETAIL = {
    slug: '02-products',
    title: 'Products',
    group: 'Catalog',
    html: '<h1>Products</h1><h2>Creating</h2><p>Steps…</p><h2>Deleting</h2><p>More…</p><p><a href="/acme/help/01-dashboard">See dashboard</a></p>',
    prev: { slug: '01-dashboard', title: 'Dashboard' },
    next: { slug: '03-billing', title: 'Billing' },
};

const mountArticle = (props: Record<string, unknown> = {}) =>
    mount(KinetixHelpArticle, {
        props: { slug: '02-products', ...props },
        global: { plugins: [i18n] },
    });

describe('KinetixHelpArticle', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        fetchMock.mockResolvedValue(DETAIL);
        window.history.replaceState({}, '', '/acme/help/02-products');
    });

    it('fetches the article and renders its html with a TOC', async () => {
        const w = mountArticle();
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            '/_kinetix/help/article/02-products',
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
            '/_kinetix/help/article/03-billing',
        );
    });

    it('shows the not-found message when the fetch fails', async () => {
        fetchMock.mockRejectedValueOnce(new Error('404'));
        const w = mountArticle();
        await flushPromises();

        expect(w.text()).toContain('This article is not available.');
    });
});
