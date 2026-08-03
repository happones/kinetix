import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: { kinetix_config: { route_prefix: '_kinetix' } },
    }),
    router: { get: vi.fn(), visit: vi.fn(), reload: vi.fn() },
    usePoll: () => ({ start: vi.fn(), stop: vi.fn() }),
    Link: {
        name: 'Link',
        template: '<a :href="href"><slot /></a>',
        props: ['href'],
    },
}));

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
                showing_records: 'Showing {from} to {to} of {total} results',
                showing_range: 'Showing {from} to {to}',
                results_count: '{count} results',
            },
        },
    },
});

const col = (name: string) => ({
    name,
    label: name,
    isSearchable: false,
    isSortable: false,
    alignment: 'left',
    isToggleable: false,
    isToggledHiddenByDefault: false,
    type: 'text',
});

const baseTable = {
    heading: null,
    description: null,
    poll: null,
    isStriped: false,
    model: 'token',
    columns: [col('title')],
    filters: [],
    recordActions: [],
    toolbarActions: [],
    bulkActions: [],
    footerActions: [],
    records: [{ id: 1, values: { title: 'A' } }],
    isPaginated: true,
    paginationPageOptions: [10],
    pagination: {
        perPage: 10,
        hasMore: false,
        currentPage: 1,
        total: 1,
        lastPage: 1,
        from: 1,
        to: 1,
    },
    state: { search: '', sort: '', direction: 'asc', filters: {}, perPage: 10 },
    queryPrefix: '',
    summaries: {},
    hasSummaries: false,
};

const stubs = {
    KinetixTableHead: true,
    KinetixTableCell: true,
    KinetixActionDropdown: true,
    KinetixTablePagination: true,
};

describe('KinetixTable result announcements', () => {
    it('announces the new result count when the table state changes', async () => {
        const wrapper = mount(KinetixTable, {
            props: { table: baseTable },
            global: { plugins: [i18n], stubs },
        });

        await wrapper.setProps({
            table: {
                ...baseTable,
                records: [
                    { id: 1, values: { title: 'A' } },
                    { id: 2, values: { title: 'B' } },
                ],
                pagination: {
                    ...baseTable.pagination,
                    total: 2,
                    to: 2,
                },
                state: { ...baseTable.state, search: 'a' },
            },
        });

        const region = document.getElementById('kinetix-live-region');
        await vi.waitFor(() => {
            expect(region?.textContent).toBe('Showing 1 to 2 of 2 results');
        });
    });

    it('announces the empty state when a filter clears the results', async () => {
        const wrapper = mount(KinetixTable, {
            props: { table: baseTable },
            global: { plugins: [i18n], stubs },
        });

        await wrapper.setProps({
            table: {
                ...baseTable,
                records: [],
                pagination: null,
                state: {
                    ...baseTable.state,
                    filters: { status: 'archived' },
                },
            },
        });

        const region = document.getElementById('kinetix-live-region');
        await vi.waitFor(() => {
            expect(region?.textContent).toBe('No results');
        });
    });

    it('stays silent when only the records refresh (polling)', async () => {
        const wrapper = mount(KinetixTable, {
            props: { table: baseTable },
            global: { plugins: [i18n], stubs },
        });

        document.getElementById('kinetix-live-region')?.remove();

        await wrapper.setProps({
            table: {
                ...baseTable,
                records: [{ id: 1, values: { title: 'A refreshed' } }],
            },
        });
        await new Promise((resolve) => requestAnimationFrame(resolve));

        // No state change → no announcement (no region was re-created).
        const region = document.getElementById('kinetix-live-region');
        expect(region?.textContent ?? '').toBe('');
    });
});
