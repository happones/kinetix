import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: { kinetix_config: { route_prefix: '_kinetix' } },
    }),
    router: { get: vi.fn(), visit: vi.fn(), reload: vi.fn() },
}));

import KinetixTableToolbar from '@/components/Table/KinetixTableToolbar.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: { en: { kinetix: { search_records: 'Search records…' } } },
});

const col = (name: string, extra: Record<string, unknown> = {}) => ({
    name,
    label: name,
    isSearchable: false,
    isSortable: false,
    alignment: 'left',
    isToggleable: false,
    isToggledHiddenByDefault: false,
    type: 'text',
    ...extra,
});

const baseTable = {
    heading: 'Products',
    description: null,
    columns: [col('name', { isSearchable: true })],
    filters: [],
    toolbarActions: [],
    savedViewsKey: null,
};

const mountToolbar = (table: Record<string, unknown>) =>
    mount(KinetixTableToolbar, {
        props: {
            table: { ...baseTable, ...table } as never,
            searchQuery: '',
            activeFilters: {},
            currentViewState: {},
            isColumnVisible: () => true,
        },
        global: {
            plugins: [i18n],
            stubs: { KinetixSavedViews: true },
        },
    });

describe('KinetixTableToolbar layout', () => {
    it('defaults to the container-adaptive arrangement', () => {
        const wrapper = mountToolbar({});

        expect(wrapper.find('.kinetix-toolbar-host').exists()).toBe(true);
        expect(wrapper.get('.kinetix-toolbar').classes()).toContain('is-auto');
    });

    it('honors a pinned inline arrangement', () => {
        const wrapper = mountToolbar({ toolbarLayout: 'inline' });

        expect(wrapper.get('.kinetix-toolbar').classes()).toContain(
            'is-inline',
        );
    });

    it('honors a pinned stacked arrangement', () => {
        const wrapper = mountToolbar({ toolbarLayout: 'stacked' });

        expect(wrapper.get('.kinetix-toolbar').classes()).toContain(
            'is-stacked',
        );
    });

    it('keeps search and buttons in dedicated regions', () => {
        const wrapper = mountToolbar({
            toolbarActions: [
                {
                    label: 'Create',
                    icon: 'plus',
                    color: null,
                    type: 'button',
                    openUrlInNewTab: false,
                },
            ],
        });

        expect(
            wrapper.get('.kinetix-toolbar-search input').attributes('type'),
        ).toBe('search');
        expect(wrapper.get('.kinetix-toolbar-buttons').text()).toContain(
            'Create',
        );
    });
});
