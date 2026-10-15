import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { createI18n } from 'vue-i18n';

const { routerMock } = vi.hoisted(() => ({
    routerMock: { visit: vi.fn() },
}));

vi.mock('@inertiajs/vue3', () => ({
    router: routerMock,
    usePage: () => ({
        props: {
            kinetix_locale: {
                enabled: true,
                current: 'es',
                locales: [
                    { code: 'en', label: 'English' },
                    { code: 'es', label: 'Español' },
                ],
            },
        },
    }),
    Head: defineComponent({ setup: () => () => null }),
    Link: defineComponent({
        props: { href: { type: String, required: true } },
        setup:
            (props, { slots, attrs }) =>
            () =>
                h('a', { ...attrs, href: props.href }, slots.default?.()),
    }),
}));

import KinetixDocs from '@/components/KinetixDocs.vue';
import { clearKinetixDocsSearchCache } from '@/composables/useKinetixDocsSearch';
import type { KinetixDocsPayload } from '@/types/kinetix';

const i18n = createI18n({
    legacy: false,
    locale: 'es',
    missingWarn: false,
    fallbackWarn: false,
    messages: {
        es: {
            kinetix: {
                docs_search: 'Buscar',
                docs_menu: 'Menú',
                docs_previous: 'Anterior',
                docs_next: 'Siguiente',
                docs_edit_page: 'Editar esta página',
                docs_updated: 'Actualizado el {date}',
                docs_translation_missing:
                    'Esta página aún no está traducida: se muestra la versión en {language}.',
            },
        },
    },
});

const payload = (
    page: Partial<KinetixDocsPayload['page']> = {},
): KinetixDocsPayload => ({
    sections: [
        { key: 'guide', title: 'Guía', url: '/docs/guide', active: true },
        { key: 'api', title: 'API', url: '/docs/api', active: false },
    ],
    section: { key: 'guide', title: 'Guía' },
    navigation: [
        {
            title: 'Básicos',
            pages: [
                {
                    title: 'Instalar',
                    url: '/docs/guide/install',
                    active: false,
                },
                {
                    title: 'Configurar',
                    url: '/docs/guide/configure',
                    active: true,
                },
            ],
        },
    ],
    page: {
        slug: 'configure',
        title: 'Configurar',
        description: 'Ajusta el entorno.',
        group: 'Básicos',
        html: '<h2 id="env">Env<a class="kx-md-anchor" href="#env">#</a></h2><p>Ver <a href="/docs/api/auth">auth</a>, <a href="#env">aquí</a> y <a href="https://example.com">fuera</a>.</p>',
        toc: [{ level: 2, id: 'env', text: 'Env' }],
        locale: 'en',
        fallback: true,
        updatedAt: '2026-10-15T10:00:00+00:00',
        editUrl: 'https://code.example/edit/en/guide/02-configure.md',
        ...page,
    },
    previous: { title: 'Instalar', url: '/docs/guide/install' },
    next: null,
    searchUrl: '/docs/search.json',
    meta: { title: 'Configurar · Guía', description: '', url: '' },
});

enableAutoUnmount(afterEach);

beforeEach(() => {
    routerMock.visit.mockReset();
    clearKinetixDocsSearchCache();
    Element.prototype.scrollIntoView = vi.fn();
});

const mountDocs = (docs = payload()) =>
    mount(KinetixDocs, {
        props: { docs },
        global: { plugins: [i18n] },
        attachTo: document.body,
    });

describe('KinetixDocs', () => {
    it('shows the sections, the active page and the page itself', () => {
        const w = mountDocs();

        expect(
            w.find('a[aria-current="page"][href="/docs/guide"]').text(),
        ).toBe('Guía');
        expect(
            w.findAll('a[aria-current="page"][href="/docs/guide/configure"]'),
        ).toHaveLength(2); // sidebar + mobile menu
        expect(w.find('h1').text()).toBe('Configurar');
        expect(w.text()).toContain('Ajusta el entorno.');
        expect(w.find('article').attributes('lang')).toBe('en');
    });

    it('says when the page is in the fallback language', () => {
        expect(mountDocs().text()).toContain(
            'Esta página aún no está traducida: se muestra la versión en English.',
        );
        expect(
            mountDocs(payload({ fallback: false, locale: 'es' })).text(),
        ).not.toContain('aún no está traducida');
    });

    it('links to the previous page and to the source', () => {
        const w = mountDocs();

        expect(w.find('a[href="/docs/guide/install"]').exists()).toBe(true);
        expect(w.text()).toContain('Anterior');
        expect(w.text()).not.toContain('Siguiente');
        expect(
            w.find('a[href^="https://code.example/edit"]').attributes('target'),
        ).toBe('_blank');
        expect(w.text()).toContain('Actualizado el');
    });

    it('visits app links through Inertia and keeps heading links on the page', async () => {
        const w = mountDocs();
        const replaceState = vi.spyOn(window.history, 'replaceState');

        await w.find('article a[href="/docs/api/auth"]').trigger('click');
        expect(routerMock.visit).toHaveBeenCalledWith('/docs/api/auth');

        await w.find('article a[href="#env"]').trigger('click');
        expect(replaceState).toHaveBeenLastCalledWith(
            window.history.state,
            '',
            '#env',
        );

        routerMock.visit.mockReset();
        await w.find('article a[href="https://example.com"]').trigger('click');
        expect(routerMock.visit).not.toHaveBeenCalled();
    });

    it('builds the on-this-page list from the headings', async () => {
        const w = mountDocs();
        await flushPromises();

        expect(
            w.findAll('a[href="#env"]').filter((a) => a.text() === 'Env')
                .length,
        ).toBeGreaterThan(0);
    });
});
