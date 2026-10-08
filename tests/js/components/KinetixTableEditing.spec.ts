import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi, beforeEach } from 'vitest';
import { createI18n } from 'vue-i18n';

const fetchMock = vi.fn();
const toastError = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: { kinetix_config: { route_prefix: '_kinetix' }, errors: {} },
    }),
    router: { get: vi.fn(), visit: vi.fn(), reload: vi.fn(), post: vi.fn() },
    usePoll: () => ({ start: vi.fn(), stop: vi.fn() }),
}));
vi.mock('vue-sonner', () => ({
    toast: { error: (m: string) => toastError(m) },
}));
vi.mock('@/composables/useKinetixHttp', async (importOriginal) => ({
    ...(await importOriginal<Record<string, unknown>>()),
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
}));

import KinetixTable from '@/components/KinetixTable.vue';

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

const record = (id: number, name: string, group: string) => ({
    id,
    values: { name },
    icons: {},
    iconColors: {},
    badgeColors: {},
    descriptions: {},
    recordUrl: null,
    actions: [],
    groupKey: group,
    groupLabel: group,
});

const baseTable = (extra: Record<string, unknown> = {}) => ({
    heading: null,
    description: null,
    poll: null,
    isStriped: false,
    model: 'token',
    columns: [col('name')],
    filters: [],
    recordActions: [],
    toolbarActions: [],
    bulkActions: [],
    footerActions: [],
    records: [record(1, 'Alpha', 'a'), record(2, 'Beta', 'b')],
    isPaginated: false,
    paginationPageOptions: [10],
    pagination: null,
    state: { search: '', sort: '', direction: 'asc', filters: {}, perPage: 10 },
    queryPrefix: '',
    summaries: {},
    hasSummaries: false,
    ...extra,
});

const mountTable = (table: Record<string, unknown>) =>
    mount(KinetixTable, {
        props: { table },
        global: { plugins: [i18n] },
        attachTo: document.body,
    });

describe('KinetixTable editing and layout', () => {
    beforeEach(() => {
        fetchMock.mockReset();
        toastError.mockReset();
    });

    // Reorder is off while grouped. The head kept its grip column while the
    // rows dropped theirs, so every cell sat under the wrong header.
    it('keeps head and rows aligned on a reorderable, grouped table', () => {
        const wrapper = mountTable(
            baseTable({
                reorderable: true,
                groups: [
                    { column: 'group', label: 'Group', collapsible: false },
                ],
                defaultGroup: 'group',
            }),
        );

        const headCells = wrapper.findAll('thead tr th').length;
        const dataRow = wrapper
            .findAll('tbody tr')
            .find(
                (tr) =>
                    tr.find('input, td').exists() &&
                    !tr.find('[colspan]').exists(),
            );

        expect(dataRow).toBeDefined();
        expect(dataRow!.findAll('td').length).toBe(headCells);
        expect(wrapper.find('.cursor-grab').exists()).toBe(false);

        wrapper.unmount();
    });

    // A refused value used to be console-only: it stayed in the input,
    // looking saved.
    it('says why a value was refused and shows the stored one again', async () => {
        fetchMock.mockRejectedValue(
            Object.assign(new Error('The name has already been taken.'), {
                status: 422,
            }),
        );

        const wrapper = mountTable(
            baseTable({
                columns: [col('name', { type: 'text-input' })],
            }),
        );

        const input = wrapper.find('tbody input');
        expect(input.exists()).toBe(true);
        (input.element as HTMLInputElement).value = 'Beta';
        await input.trigger('change');
        await flushPromises();

        expect(toastError).toHaveBeenCalledWith(
            'The name has already been taken.',
        );
        expect(
            (wrapper.find('tbody input').element as HTMLInputElement).value,
        ).toBe('Alpha');

        wrapper.unmount();
    });

    describe('row memoization', () => {
        // Every data row translates its select checkbox's label once per
        // render, so counting the translations counts row renders.
        let rowRenders = 0;
        const counting = createI18n({
            legacy: false,
            locale: 'en',
            missingWarn: false,
            fallbackWarn: false,
            messages: {
                en: {
                    kinetix: {
                        select_row: () => {
                            rowRenders++;

                            return 'Select row';
                        },
                    },
                },
            },
        });
        const selectable = () =>
            baseTable({
                bulkActions: [{ name: 'archive', label: 'Archive' }],
                records: [
                    record(1, 'Alpha', 'a'),
                    record(2, 'Beta', 'a'),
                    record(3, 'Gamma', 'a'),
                ],
            });

        it('re-renders only the row whose selection changed', async () => {
            const wrapper = mount(KinetixTable, {
                props: { table: selectable() },
                global: { plugins: [counting] },
            });
            rowRenders = 0;

            await wrapper
                .findAll('tbody [role="checkbox"]')[0]
                .trigger('click');

            expect(rowRenders).toBe(1);
        });

        it('re-renders every row when the host renders cells itself', async () => {
            const wrapper = mount(KinetixTable, {
                props: { table: selectable() },
                slots: { 'cell-name': '<b>custom</b>' },
                global: { plugins: [counting] },
            });
            rowRenders = 0;

            await wrapper
                .findAll('tbody [role="checkbox"]')[0]
                .trigger('click');

            expect(rowRenders).toBe(3);
        });

        it('shows fresh values after a reload', async () => {
            const wrapper = mount(KinetixTable, {
                props: { table: selectable() },
                global: { plugins: [counting] },
            });

            await wrapper.setProps({
                table: {
                    ...selectable(),
                    records: [record(1, 'Renamed', 'a')],
                },
            });

            expect(wrapper.find('tbody').text()).toContain('Renamed');
        });
    });
});
