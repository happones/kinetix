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

import KinetixEmptyState from '@/components/KinetixEmptyState.vue';
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
        isPaginated: false,
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
    KinetixTablePagination: true,
};

describe('table empty state', () => {
    it('renders the configured empty state with its CTA actions', () => {
        const wrapper = mount(KinetixTable, {
            props: {
                table: baseTable({
                    emptyState: {
                        heading: 'No widgets yet',
                        description: 'Create the first one.',
                        icon: 'package',
                        actions: [
                            {
                                type: 'action',
                                name: 'create',
                                label: 'New widget',
                                color: 'primary',
                            },
                        ],
                    },
                }),
            },
            global: { plugins: [i18n], stubs },
        });

        const empty = wrapper.findComponent(KinetixEmptyState);
        expect(empty.exists()).toBe(true);
        expect(empty.text()).toContain('No widgets yet');
        expect(empty.text()).toContain('Create the first one.');

        const cta = empty
            .findAll('button')
            .find((b) => b.text().includes('New widget'));
        expect(cta).toBeTruthy();

        wrapper.unmount();
    });

    it('falls back to the plain no-records line without configuration', () => {
        const wrapper = mount(KinetixTable, {
            props: { table: baseTable() },
            global: { plugins: [i18n], stubs },
        });

        expect(wrapper.findComponent(KinetixEmptyState).exists()).toBe(false);
        expect(wrapper.text()).toContain('No records found');

        wrapper.unmount();
    });
});
