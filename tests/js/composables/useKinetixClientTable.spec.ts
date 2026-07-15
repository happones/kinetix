import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import { useKinetixClientTable } from '@/composables/useKinetixClientTable';

const columns = [{ name: 'name' }, { name: 'age' }] as any;

const record = (id: number, name: string, age: number) => ({
    id,
    values: { name, age },
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

const records = [
    record(1, 'Charlie', 30),
    record(2, 'alice', 25),
    record(3, 'Bob', 40),
    record(4, 'dave', 20),
    record(5, 'Eve', 35),
];

// useVueTable relies on Vue reactivity/effect scope, so exercise the composable
// inside a mounted component rather than calling it bare.
const mountClient = (pageSize = 10) => {
    let api: ReturnType<typeof useKinetixClientTable>;

    const Harness = defineComponent({
        setup() {
            api = useKinetixClientTable({
                records: () => records,
                columns: () => columns,
                pageSize,
            });

            return () => h('div');
        },
    });

    const wrapper = mount(Harness);

    return { wrapper, api: api! };
};

const names = (rows: any[]) => rows.map((r) => r.values.name);

describe('useKinetixClientTable', () => {
    it('paginates client-side', async () => {
        const { api } = mountClient(2);
        await nextTick();

        expect(api.pageRecords.value).toHaveLength(2);
        expect(api.pagination.value.total).toBe(5);
        expect(api.pagination.value.lastPage).toBe(3);
        expect(api.pagination.value.from).toBe(1);
        expect(api.pagination.value.to).toBe(2);

        api.setPage(3);
        await nextTick();
        expect(api.pageRecords.value).toHaveLength(1); // last page has the 5th row
        expect(api.pagination.value.currentPage).toBe(3);
    });

    it('sorts by a column, toggling direction', async () => {
        const { api } = mountClient();
        await nextTick();

        api.toggleSort('name');
        await nextTick();
        expect(api.sortName.value).toBe('name');
        expect(api.sortDirection.value).toBe('asc');
        // Case-insensitive alphanumeric order.
        expect(names(api.pageRecords.value)).toEqual([
            'alice',
            'Bob',
            'Charlie',
            'dave',
            'Eve',
        ]);

        api.toggleSort('name');
        await nextTick();
        expect(api.sortDirection.value).toBe('desc');
        expect(names(api.pageRecords.value)[0]).toBe('Eve');
    });

    it('filters by the global search across columns', async () => {
        const { api } = mountClient();
        await nextTick();

        api.search.value = 'ali';
        await nextTick();
        expect(names(api.pageRecords.value)).toEqual(['alice']);

        // Also matches a numeric column's serialized value.
        api.search.value = '40';
        await nextTick();
        expect(names(api.pageRecords.value)).toEqual(['Bob']);
    });

    it('resets to the first page when the search changes', async () => {
        const { api } = mountClient(2);
        await nextTick();

        api.setPage(3);
        await nextTick();
        expect(api.pagination.value.currentPage).toBe(3);

        api.search.value = 'e';
        await nextTick();
        expect(api.pagination.value.currentPage).toBe(1);
    });
});
