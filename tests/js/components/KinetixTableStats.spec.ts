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
    messages: { en: { kinetix: {} } },
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

const table = {
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
    records: [],
    isPaginated: false,
    paginationPageOptions: [10],
    pagination: null,
    state: { search: '', sort: '', direction: 'asc', filters: {}, perPage: 10 },
    queryPrefix: '',
    summaries: {},
    hasSummaries: false,
};

const mountTable = (t: any) =>
    mount(KinetixTable, {
        props: { table: t },
        global: {
            plugins: [i18n],
            stubs: {
                KinetixTableHead: true,
                KinetixTableCell: true,
                KinetixActionDropdown: true,
                KinetixTablePagination: true,
            },
        },
    });

describe('KinetixTable stat cards', () => {
    it('renders a card per stat with its label and value', () => {
        const wrapper = mountTable({
            ...table,
            stats: [
                {
                    label: 'Total Books',
                    value: '12,480',
                    icon: 'book',
                    color: 'info',
                },
                {
                    label: 'Overdue',
                    value: '34',
                    icon: 'chart-bar',
                    color: 'danger',
                },
            ],
        });

        const cards = wrapper.findAll('.kinetix-table-stats > *');
        expect(cards).toHaveLength(2);
        expect(wrapper.text()).toContain('Total Books');
        expect(wrapper.text()).toContain('12,480');
        expect(wrapper.text()).toContain('Overdue');
        expect(wrapper.text()).toContain('34');
    });

    it('renders nothing when the table declares no stats', () => {
        const wrapper = mountTable(table);

        expect(wrapper.text()).not.toContain('Total Books');
        expect(wrapper.find('.kinetix-table-stats').exists()).toBe(false);
    });

    it('renders nothing for an empty stats array', () => {
        const wrapper = mountTable({ ...table, stats: [] });

        expect(wrapper.find('.kinetix-table-stats').exists()).toBe(false);
    });

    it('shows an optional description', () => {
        const wrapper = mountTable({
            ...table,
            stats: [
                {
                    label: 'Revenue',
                    value: '$1,204',
                    description: 'This month',
                    color: 'success',
                },
            ],
        });

        expect(wrapper.text()).toContain('This month');
    });

    it('renders a colored trend chip when descriptionColor is set', () => {
        const wrapper = mountTable({
            ...table,
            stats: [
                {
                    label: 'Overdue',
                    value: '34',
                    description: '+12% vs last month',
                    descriptionColor: 'danger',
                    descriptionIcon: 'trending-up',
                },
            ],
        });

        const chip = wrapper.find('.kinetix-table-stats .rounded-full');
        expect(chip.exists()).toBe(true);
        expect(chip.classes()).toContain('text-destructive');
        expect(chip.text()).toContain('+12% vs last month');
        expect(chip.find('svg').exists()).toBe(true);
    });

    it('keeps an uncolored description as plain muted text', () => {
        const wrapper = mountTable({
            ...table,
            stats: [
                {
                    label: 'Revenue',
                    value: '$1,204',
                    description: 'This month',
                },
            ],
        });

        expect(wrapper.text()).toContain('This month');
        expect(
            wrapper.find('.kinetix-table-stats .rounded-full').exists(),
        ).toBe(false);
    });

    it('renders a sparkline tinted by the trend color', () => {
        const wrapper = mountTable({
            ...table,
            stats: [
                {
                    label: 'Loans',
                    value: '842',
                    descriptionColor: 'success',
                    chart: [3, 5, 4, 8, 9],
                },
            ],
        });

        const sparkline = wrapper.find('.kinetix-table-stats svg path');
        expect(sparkline.exists()).toBe(true);
        expect(wrapper.find('.text-success svg').exists()).toBe(true);
    });

    it('prefers the icon badge over the sparkline when both are set', () => {
        const wrapper = mountTable({
            ...table,
            stats: [
                {
                    label: 'Loans',
                    value: '842',
                    icon: 'book',
                    color: 'info',
                    chart: [3, 5, 4],
                },
            ],
        });

        // One svg (the icon), no sparkline path pair.
        expect(wrapper.find('.kinetix-table-stats .size-10 svg').exists()).toBe(
            true,
        );
        expect(
            wrapper.find('.kinetix-table-stats linearGradient').exists(),
        ).toBe(false);
    });

    it('renders a linked card as an anchor', () => {
        const wrapper = mountTable({
            ...table,
            stats: [
                { label: 'On loan', value: '842', url: '/books?status=loan' },
            ],
        });

        const link = wrapper.find('a[href="/books?status=loan"]');
        expect(link.exists()).toBe(true);
        expect(link.text()).toContain('842');
    });

    it('keeps a plain card unlinked', () => {
        const wrapper = mountTable({
            ...table,
            stats: [{ label: 'On loan', value: '842' }],
        });

        expect(wrapper.find('a').exists()).toBe(false);
    });

    it('still passes a consumer class to the table card, not the new wrapper', () => {
        // The root gained a wrapper for the cards; attrs must keep landing on
        // the card so existing `<KinetixTable class="…">` usage is unaffected.
        const wrapper = mount(KinetixTable, {
            props: { table },
            attrs: { class: 'my-custom-class' },
            global: {
                plugins: [i18n],
                stubs: {
                    KinetixTableHead: true,
                    KinetixTableCell: true,
                    KinetixActionDropdown: true,
                    KinetixTablePagination: true,
                },
            },
        });

        expect(wrapper.find('.kinetix-table-wrapper').classes()).toContain(
            'my-custom-class',
        );
        expect(wrapper.find('.kinetix-table-root').classes()).not.toContain(
            'my-custom-class',
        );
    });
});
