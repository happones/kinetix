import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { createI18n } from 'vue-i18n';

import KinetixTablePagination from '@/components/Table/KinetixTablePagination.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: {
        en: {
            kinetix: {
                per_page: 'Per page:',
                no_records: 'No results',
                page_of: 'Page {current} of {total}',
                page_number: 'Page {current}',
                showing_records: 'Showing {from} to {to} of {total} results',
                showing_range: 'Showing {from} to {to}',
            },
        },
    },
});

const lengthAware = {
    currentPage: 2,
    perPage: 10,
    hasMore: true,
    total: 25,
    lastPage: 3,
    from: 11,
    to: 20,
};

/** What a simple-paginated table sends: no total, no last page. */
const simple = {
    currentPage: 2,
    perPage: 10,
    hasMore: true,
    total: null,
    lastPage: null,
    from: 11,
    to: 20,
};

/** What a cursor-paginated table sends: no page number, no offsets, no total. */
const cursor = {
    currentPage: null,
    perPage: 10,
    hasMore: true,
    total: null,
    lastPage: null,
    from: null,
    to: null,
    nextCursor: 'next-abc',
    prevCursor: 'prev-xyz',
    onFirstPage: false,
};

const render = (pagination: Record<string, unknown>) =>
    mount(KinetixTablePagination, {
        props: { pagination, paginationPageOptions: [10, 25] },
        global: { plugins: [i18n] },
    });

describe('KinetixTablePagination', () => {
    describe('length-aware mode', () => {
        it('shows the total and the page count', () => {
            const text = render(lengthAware).text();

            expect(text).toContain('Showing 11 to 20 of 25 results');
            expect(text).toContain('Page 2 of 3');
        });

        it('renders the first and last jumps', () => {
            const wrapper = render(lengthAware);

            expect(wrapper.find('[data-testid="page-first"]').exists()).toBe(
                true,
            );
            expect(wrapper.find('[data-testid="page-last"]').exists()).toBe(
                true,
            );
        });
    });

    describe('simple mode', () => {
        it('omits the total rather than rendering a placeholder', () => {
            const text = render(simple).text();

            expect(text).toContain('Showing 11 to 20');
            expect(text).not.toContain('results');
            expect(text).toContain('Page 2');
            expect(text).not.toContain('Page 2 of');
        });

        it('drops the first/last jumps, which need a total', () => {
            const wrapper = render(simple);

            expect(wrapper.find('[data-testid="page-first"]').exists()).toBe(
                false,
            );
            expect(wrapper.find('[data-testid="page-last"]').exists()).toBe(
                false,
            );
            expect(wrapper.find('[data-testid="page-prev"]').exists()).toBe(
                true,
            );
            expect(wrapper.find('[data-testid="page-next"]').exists()).toBe(
                true,
            );
        });

        it('enables next from hasMore', () => {
            const next = render(simple).find('[data-testid="page-next"]');

            expect(next.attributes('disabled')).toBeUndefined();
        });

        it('disables next on the last page', () => {
            const next = render({ ...simple, hasMore: false }).find(
                '[data-testid="page-next"]',
            );

            expect(next.attributes('disabled')).toBeDefined();
        });

        it('disables previous on the first page', () => {
            const prev = render({ ...simple, currentPage: 1 }).find(
                '[data-testid="page-prev"]',
            );

            expect(prev.attributes('disabled')).toBeDefined();
        });

        it('emits the next page', async () => {
            const wrapper = render(simple);

            await wrapper.find('[data-testid="page-next"]').trigger('click');

            expect(wrapper.emitted('change-page')?.[0]).toEqual([3]);
        });

        it('falls back to no-records when the page is empty', () => {
            expect(
                render({ ...simple, from: null, to: null }).text(),
            ).toContain('No results');
        });
    });

    describe('cursor mode', () => {
        it('renders neither a total nor a page number', () => {
            const text = render(cursor).text();

            expect(text).not.toContain('Showing');
            expect(text).not.toContain('Page');
        });

        it('offers prev/next only — there is no last page to jump to', () => {
            const wrapper = render(cursor);

            expect(wrapper.find('[data-testid="page-first"]').exists()).toBe(
                false,
            );
            expect(wrapper.find('[data-testid="page-last"]').exists()).toBe(
                false,
            );
            expect(wrapper.find('[data-testid="page-prev"]').exists()).toBe(
                true,
            );
            expect(wrapper.find('[data-testid="page-next"]').exists()).toBe(
                true,
            );
        });

        it('emits the next cursor rather than a page number', async () => {
            const wrapper = render(cursor);

            await wrapper.find('[data-testid="page-next"]').trigger('click');

            expect(wrapper.emitted('change-cursor')?.[0]).toEqual(['next-abc']);
            expect(wrapper.emitted('change-page')).toBeUndefined();
        });

        it('emits the previous cursor going back', async () => {
            const wrapper = render(cursor);

            await wrapper.find('[data-testid="page-prev"]').trigger('click');

            expect(wrapper.emitted('change-cursor')?.[0]).toEqual(['prev-xyz']);
        });

        it('disables previous on the first page', () => {
            const wrapper = render({
                ...cursor,
                onFirstPage: true,
                prevCursor: null,
            });

            expect(
                wrapper
                    .find('[data-testid="page-prev"]')
                    .attributes('disabled'),
            ).toBeDefined();
        });

        it('disables next at the end of the set', () => {
            const wrapper = render({
                ...cursor,
                hasMore: false,
                nextCursor: null,
            });

            expect(
                wrapper
                    .find('[data-testid="page-next"]')
                    .attributes('disabled'),
            ).toBeDefined();
        });
    });
});
