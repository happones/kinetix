import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

const usePollMock = vi.hoisted(() =>
    vi.fn((..._args: unknown[]) => ({ start: vi.fn(), stop: vi.fn() })),
);

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: { kinetix_config: { route_prefix: '_kinetix' } },
    }),
    router: { get: vi.fn(), visit: vi.fn(), reload: vi.fn() },
    usePoll: usePollMock,
    Link: {
        name: 'Link',
        template: '<a :href="href"><slot /></a>',
        props: ['href'],
    },
}));

import { router } from '@inertiajs/vue3';
import KinetixTable from '@/components/KinetixTable.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: {
        en: {
            kinetix: {
                no_records: 'No results',
                no_records_found: 'No records found',
                results_count: '{count} results',
                showing_records: 'Showing {from} to {to} of {total} results',
                showing_records_approximate:
                    'Showing {from} to {to} of about {total} results',
                table_timed_out_heading: 'This list took too long to load',
                table_timed_out: 'Narrow the search or try again.',
                table_retry: 'Try again',
            },
        },
    },
});

const baseTable = (overrides: Record<string, any> = {}) =>
    ({
        heading: null,
        description: null,
        poll: null,
        isStriped: false,
        model: 'token',
        columns: [
            {
                name: 'name',
                label: 'Name',
                isSearchable: false,
                isSortable: false,
                alignment: 'left',
                isToggleable: false,
                isToggledHiddenByDefault: false,
                type: 'text',
            },
        ],
        filters: [],
        recordActions: [],
        toolbarActions: [],
        bulkActions: [],
        footerActions: [],
        records: [],
        isPaginated: true,
        paginationPageOptions: [10],
        pagination: null,
        state: {
            search: '',
            sort: '',
            direction: 'asc',
            filters: {},
            perPage: 10,
        },
        queryPrefix: '',
        summaries: {},
        hasSummaries: false,
        ...overrides,
    }) as any;

const stubs = {
    KinetixTableHead: true,
    KinetixTableCell: true,
};

const mountTable = (table: any, props: Record<string, unknown> = {}) =>
    mount(KinetixTable, {
        props: { table, ...props },
        global: { plugins: [i18n], stubs },
        attachTo: document.body,
    });

const timedOut = () => baseTable({ timedOut: true });

const rows = [{ id: 1, values: { name: 'A' } }];

describe('table timed-out state', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('shows a warning alert with a retry instead of the empty state', () => {
        const wrapper = mountTable(timedOut());

        expect(wrapper.text()).toContain('This list took too long to load');
        expect(wrapper.text()).toContain('Narrow the search or try again.');
        expect(wrapper.text()).not.toContain('No records found');
        // The table's own live region announces it: the alert must not too.
        expect(wrapper.find('[role="status"]').exists()).toBe(false);
        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
        expect(
            wrapper.findAll('button').some((b) => b.text() === 'Try again'),
        ).toBe(true);
    });

    it('does not show the alert for a normal empty table', () => {
        const wrapper = mountTable(baseTable());

        expect(wrapper.text()).not.toContain('took too long');
        expect(wrapper.text()).toContain('No records found');
    });

    it('retries with a full reload when no reloadOnly is named', async () => {
        const wrapper = mountTable(timedOut());

        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'Try again')!
            .trigger('click');

        const options = vi.mocked(router.reload).mock.calls[0][0] as any;
        expect('only' in options).toBe(false);
    });

    it('retries with a partial reload and shows loading until it finishes', async () => {
        const wrapper = mountTable(timedOut(), { reloadOnly: ['table'] });
        const retry = () =>
            wrapper.findAll('button').find((b) => b.text() === 'Try again')!;

        await retry().trigger('click');

        const options = vi.mocked(router.reload).mock.calls[0][0] as any;
        expect(options.only).toEqual(['table']);
        expect(retry().attributes('disabled')).toBeDefined();

        // A second click while loading does not fire another visit.
        await retry().trigger('click');
        expect(router.reload).toHaveBeenCalledTimes(1);

        options.onFinish();
        await flushPromises();
        expect(retry().attributes('disabled')).toBeUndefined();
    });

    it('moves focus to the table region after a successful retry', async () => {
        const wrapper = mountTable(timedOut(), { reloadOnly: ['table'] });

        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'Try again')!
            .trigger('click');
        const options = vi.mocked(router.reload).mock.calls[0][0] as any;

        await wrapper.setProps({ table: baseTable({ records: rows }) });
        options.onFinish();
        await flushPromises();

        const region = wrapper.get('[tabindex="-1"].kinetix-scroll-x');
        expect(document.activeElement).toBe(region.element);
    });

    it('leaves focus alone when the user moved it elsewhere during the retry', async () => {
        const wrapper = mountTable(timedOut(), { reloadOnly: ['table'] });
        const input = document.createElement('input');
        document.body.appendChild(input);

        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'Try again')!
            .trigger('click');
        const options = vi.mocked(router.reload).mock.calls[0][0] as any;

        input.focus();
        await wrapper.setProps({ table: baseTable({ records: rows }) });
        options.onFinish();
        await flushPromises();

        expect(document.activeElement).toBe(input);
        input.remove();
    });

    it('names the focus region after the table heading', () => {
        const wrapper = mountTable(baseTable({ heading: 'Orders' }));
        const region = wrapper.get('[tabindex="-1"].kinetix-scroll-x');

        expect(region.attributes('aria-label')).toBe('Orders');
    });

    it('leaves focus alone when the retry timed out again', async () => {
        const wrapper = mountTable(timedOut());

        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'Try again')!
            .trigger('click');
        const options = vi.mocked(router.reload).mock.calls[0][0] as any;

        options.onFinish();
        await flushPromises();

        const region = wrapper.get('[tabindex="-1"].kinetix-scroll-x');
        expect(document.activeElement).not.toBe(region.element);
    });

    it('announces the timeout first', async () => {
        const wrapper = mountTable(baseTable({ records: rows }));

        await wrapper.setProps({
            table: baseTable({
                timedOut: true,
                state: { ...baseTable().state, search: 'x' },
            }),
        });

        const region = document.getElementById('kinetix-live-region');
        await vi.waitFor(() => {
            expect(region?.textContent).toBe(
                'This list took too long to load. Narrow the search or try again.',
            );
        });
    });

    it('announces an approximate total as "about"', async () => {
        const wrapper = mountTable(baseTable({ records: rows }));

        await wrapper.setProps({
            table: baseTable({
                records: rows,
                pagination: {
                    perPage: 10,
                    hasMore: true,
                    currentPage: 1,
                    total: 50000,
                    totalIsApproximate: true,
                    lastPage: 5000,
                    from: 1,
                    to: 10,
                },
                state: { ...baseTable().state, search: 'y' },
            }),
        });

        const region = document.getElementById('kinetix-live-region');
        await vi.waitFor(() => {
            expect(region?.textContent).toBe(
                'Showing 1 to 10 of about 50000 results',
            );
        });
    });
});

describe('table reloadOnly', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('polls with `only` when named', () => {
        mountTable(baseTable({ poll: '10s' }), { reloadOnly: ['table'] });

        expect(usePollMock.mock.calls[0][1]).toEqual({ only: ['table'] });
    });

    it('polls with the untouched options without it', () => {
        mountTable(baseTable({ poll: '10s' }));

        expect(usePollMock.mock.calls[0][1]).toEqual({});
    });

    it('sends `only` on a page change visit', async () => {
        const wrapper = mountTable(
            baseTable({
                records: rows,
                pagination: {
                    perPage: 10,
                    hasMore: true,
                    currentPage: 1,
                    total: 30,
                    lastPage: 3,
                    from: 1,
                    to: 10,
                },
            }),
            { reloadOnly: ['table'] },
        );

        wrapper
            .findComponent({ name: 'KinetixTablePagination' })
            .vm.$emit('change-page', 2);
        await flushPromises();

        const options = vi.mocked(router.get).mock.calls[0][2] as any;
        expect(options.only).toEqual(['table']);
    });

    it('sends no `only` on a page change without it', async () => {
        const wrapper = mountTable(
            baseTable({
                records: rows,
                pagination: {
                    perPage: 10,
                    hasMore: true,
                    currentPage: 1,
                    total: 30,
                    lastPage: 3,
                    from: 1,
                    to: 10,
                },
            }),
        );

        wrapper
            .findComponent({ name: 'KinetixTablePagination' })
            .vm.$emit('change-page', 2);
        await flushPromises();

        const options = vi.mocked(router.get).mock.calls[0][2] as any;
        expect('only' in options).toBe(false);
    });
});

describe('relation manager table refreshes', () => {
    it('drives its table visits with `only` through reloadOnly', async () => {
        const wrapper = mountTable(
            baseTable({
                records: rows,
                pagination: {
                    perPage: 10,
                    hasMore: true,
                    currentPage: 1,
                    total: 30,
                    lastPage: 3,
                    from: 1,
                    to: 10,
                },
            }),
        );
        const { default: Manager } =
            await import('@/components/KinetixRelationManager.vue');
        const host = mount(Manager, {
            props: {
                manager: {
                    title: 'Tags',
                    relationship: 'tags',
                    table: wrapper.props('table'),
                    badge: null,
                    badgeColor: null,
                },
                reloadOnly: ['record'],
            } as any,
            global: { plugins: [i18n], stubs },
        });

        host.findComponent({ name: 'KinetixTablePagination' }).vm.$emit(
            'change-page',
            2,
        );
        await flushPromises();

        const options = vi.mocked(router.get).mock.calls.at(-1)![2] as any;
        expect(options.only).toEqual(['record']);
    });
});
