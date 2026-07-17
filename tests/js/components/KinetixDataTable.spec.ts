import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({
    router: { visit: vi.fn() },
    usePage: () => ({ props: {} }),
}));

import KinetixDataTable from '@/components/KinetixDataTable.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    messages: {
        en: {
            kinetix: {
                search_records: 'Search',
                columns: 'Columns',
                toggle_columns: 'Toggle',
                no_records_found: 'No records',
                showing_records: 'Showing {from} to {to} of {total}',
            },
        },
    },
});

const column = (name: string, extra: Record<string, any> = {}) => ({
    name,
    label: name,
    type: 'text',
    isSearchable: true,
    isSortable: true,
    alignment: 'left',
    ...extra,
});

const record = (id: number, name: string) => ({
    id,
    values: { name },
    actions: [],
    descriptions: {},
    icons: {},
    iconColors: {},
    badgeColors: {},
    progress: {},
    progressColors: {},
    viewProps: {},
    recordUrl: null,
});

const table = (overrides: Record<string, any> = {}) => ({
    heading: null,
    description: null,
    poll: null,
    isStriped: false,
    model: 'App\\Models\\Item',
    columns: [column('name')],
    filters: [],
    recordActions: [],
    toolbarActions: [],
    bulkActions: [],
    footerActions: [],
    records: [record(1, 'Charlie'), record(2, 'alice'), record(3, 'Bob')],
    isPaginated: true,
    paginationPageOptions: [5, 10, 25],
    pagination: null,
    state: { search: '', sort: '', direction: 'asc', filters: {}, perPage: 10 },
    queryPrefix: '',
    clientSide: true,
    ...overrides,
});

const mountTable = (overrides = {}) =>
    mount(KinetixDataTable, {
        props: { table: table(overrides) },
        global: { plugins: [i18n] },
    });

const bodyNames = (wrapper: any) =>
    wrapper.findAll('tbody tr td:first-child').map((td: any) => td.text());

describe('KinetixDataTable', () => {
    it('renders all rows client-side', () => {
        const wrapper = mountTable();
        expect(bodyNames(wrapper)).toEqual(['Charlie', 'alice', 'Bob']);
    });

    it('sorts client-side when a sortable header is clicked', async () => {
        const wrapper = mountTable();

        const header = wrapper.findAll('thead th button')[0];
        await header.trigger('click');
        await nextTick();

        expect(bodyNames(wrapper)).toEqual(['alice', 'Bob', 'Charlie']);
    });

    it('shows the empty state when there are no rows', () => {
        const wrapper = mountTable({ records: [] });
        expect(wrapper.text()).toContain('No records');
    });

    it('renders toolbar (header) actions', () => {
        // Regression: client-side mode previously dropped table.toolbarActions.
        const wrapper = mountTable({
            toolbarActions: [{ label: 'New item', icon: null, color: null }],
        });

        const btn = wrapper
            .findAll('button')
            .find((b) => b.text() === 'New item');
        expect(btn?.exists()).toBe(true);
    });
});
