import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: { kinetix_config: { route_prefix: '_kinetix' }, errors: {} },
    }),
    router: { get: vi.fn(), visit: vi.fn(), reload: vi.fn(), post: vi.fn() },
    usePoll: () => ({ start: vi.fn(), stop: vi.fn() }),
}));

import KinetixTable from '@/components/KinetixTable.vue';
import { KINETIX_TABLE_CELL_LOADERS } from '@/components/Table/kinetixTableCellLoaders';

/**
 * Input cells download on demand, started at table setup from the column
 * types present. Nothing is preloaded here: the code arrives for real.
 */
const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: { en: {} },
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

const table = (columns: unknown[]) => ({
    heading: null,
    description: null,
    poll: null,
    isStriped: false,
    model: 'token',
    columns,
    filters: [],
    recordActions: [],
    toolbarActions: [],
    bulkActions: [],
    footerActions: [],
    records: [
        {
            id: 1,
            values: { name: 'Alpha', stock: 3 },
            icons: {},
            iconColors: {},
            badgeColors: {},
            descriptions: {},
            recordUrl: null,
            actions: [],
        },
    ],
    isPaginated: false,
    paginationPageOptions: [10],
    pagination: null,
    state: { search: '', sort: '', direction: 'asc', filters: {}, perPage: 10 },
    queryPrefix: '',
    summaries: {},
    hasSummaries: false,
});

const mountTable = (columns: unknown[]) =>
    mount(KinetixTable, {
        props: { table: table(columns) as never },
        global: { plugins: [i18n] },
        attachTo: document.body,
    });

const spyPreloads = () =>
    Object.fromEntries(
        Object.entries(KINETIX_TABLE_CELL_LOADERS).map(([type, entry]) => [
            type,
            vi.spyOn(entry, 'preload'),
        ]),
    );

afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

describe('KinetixTable — lazy input cells', () => {
    it('loads no input cell for a table without input columns', async () => {
        const preloads = spyPreloads();
        const wrapper = mountTable([col('name'), col('stock')]);
        await flushPromises();

        for (const [type, spy] of Object.entries(preloads)) {
            expect(spy, type).not.toHaveBeenCalled();
            expect(KINETIX_TABLE_CELL_LOADERS[type].loaded, type).toBe(false);
        }

        expect(wrapper.text()).toContain('Alpha');
        wrapper.unmount();
    });

    it('starts a number column’s cell at setup and renders it once it arrives', async () => {
        const preloads = spyPreloads();
        const wrapper = mountTable([
            col('name'),
            col('stock', { type: 'number-input' }),
        ]);

        // At setup, before any row has rendered its cell.
        expect(preloads['number-input']).toHaveBeenCalledTimes(1);
        expect(preloads['select-input']).not.toHaveBeenCalled();

        const NumberInputCell =
            await KINETIX_TABLE_CELL_LOADERS['number-input'].preload();

        await vi.waitFor(async () => {
            await flushPromises();
            expect(wrapper.findComponent(NumberInputCell).exists()).toBe(true);
        });
        expect(wrapper.find('[data-kinetix-lazy-skeleton]').exists()).toBe(
            false,
        );
        wrapper.unmount();
    });
});
