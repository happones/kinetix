import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

const { routerMock } = vi.hoisted(() => ({
    routerMock: { visit: vi.fn() },
}));

vi.mock('@inertiajs/vue3', () => ({ router: routerMock }));

import KinetixDocsSearch from '@/components/KinetixDocsSearch.vue';
import { clearKinetixDocsSearchCache } from '@/composables/useKinetixDocsSearch';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: {
        en: {
            kinetix: {
                docs_search: 'Search docs',
                docs_search_empty: 'No results for “{query}”.',
            },
        },
    },
});

const index = {
    data: [
        {
            section: 'guide',
            sectionTitle: 'Guide',
            slug: 'install',
            title: 'Install',
            group: '',
            url: '/docs/guide/install',
            headings: [],
            text: 'Install the CLI.',
        },
        {
            section: 'guide',
            sectionTitle: 'Guide',
            slug: 'tokens',
            title: 'Tokens',
            group: '',
            url: '/docs/guide/tokens',
            headings: [{ id: 'cli', text: 'CLI tokens' }],
            text: 'Tokens for the CLI.',
        },
    ],
};

const fetchMock = vi.fn();

enableAutoUnmount(afterEach);

beforeEach(() => {
    routerMock.visit.mockReset();
    clearKinetixDocsSearchCache();
    fetchMock
        .mockReset()
        .mockResolvedValue({ ok: true, json: async () => index });
    vi.stubGlobal('fetch', fetchMock);
    Element.prototype.scrollIntoView = vi.fn();
});

const mountSearch = () =>
    mount(KinetixDocsSearch, {
        props: { url: '/docs/search.json' },
        global: { plugins: [i18n] },
        attachTo: document.body,
    });

const combobox = () =>
    document.querySelector<HTMLInputElement>('input[role="combobox"]')!;

describe('KinetixDocsSearch', () => {
    it('opens on "/" and loads the index once', async () => {
        mountSearch();

        window.dispatchEvent(new KeyboardEvent('keydown', { key: '/' }));
        await flushPromises();

        expect(combobox()).not.toBeNull();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe('/docs/search.json');
    });

    it('does not steal "/" from a text field', async () => {
        mountSearch();
        const field = document.createElement('input');
        document.body.appendChild(field);

        field.dispatchEvent(
            new KeyboardEvent('keydown', { key: '/', bubbles: true }),
        );
        await flushPromises();

        expect(combobox()).toBeNull();
        field.remove();
    });

    it('picks a result with the arrow keys and Enter', async () => {
        const w = mountSearch();

        await w.get('button').trigger('click');
        await flushPromises();

        const input = combobox();
        input.value = 'cli';
        input.dispatchEvent(new Event('input'));
        await flushPromises();

        const options = document.querySelectorAll('[role="option"]');
        expect(options).toHaveLength(2);
        expect(options[0].getAttribute('aria-selected')).toBe('true');
        expect(input.getAttribute('aria-activedescendant')).toBe(options[0].id);

        input.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown' }));
        await flushPromises();
        expect(
            document
                .querySelectorAll('[role="option"]')[1]
                .getAttribute('aria-selected'),
        ).toBe('true');

        input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
        await flushPromises();

        expect(routerMock.visit).toHaveBeenCalledTimes(1);
        expect(routerMock.visit.mock.calls[0][0]).toMatch(
            /^\/docs\/guide\/(install|tokens#cli)$/,
        );
    });

    it('says when nothing matches', async () => {
        const w = mountSearch();

        await w.get('button').trigger('click');
        await flushPromises();

        const input = combobox();
        input.value = 'zzz';
        input.dispatchEvent(new Event('input'));
        await flushPromises();

        expect(document.body.textContent).toContain('No results for “zzz”.');
    });
});
