import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive, ref } from 'vue';

const { routerGet } = vi.hoisted(() => ({ routerGet: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({ router: { get: routerGet } }));

import { useKinetixTableQuery } from '@/composables/useKinetixTableQuery';

const harness = (initialSearch: string, searchMinLength?: number) => {
    const table = reactive({
        queryPrefix: '',
        state: {
            search: initialSearch,
            sort: '',
            direction: 'asc',
            perPage: 10,
            filters: {},
        },
        pagination: null,
        searchMinLength,
    });
    const searchQuery = ref(initialSearch);
    let api!: ReturnType<typeof useKinetixTableQuery>;

    mount(
        defineComponent({
            setup() {
                api = useKinetixTableQuery({
                    table: () => table as never,
                    searchQuery,
                    activeFilters: ref({}),
                    searchDebounce: 100,
                });

                return () => h('div');
            },
        }),
    );

    const type = async (value: string) => {
        searchQuery.value = value;
        api.onSearchInput();
        await vi.advanceTimersByTimeAsync(100);
    };

    return { table, searchQuery, type };
};

describe('table search', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        routerGet.mockClear();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('reloads for a new term', async () => {
        const { type } = harness('');

        await type('ann');

        expect(routerGet).toHaveBeenCalledTimes(1);
        expect(routerGet.mock.calls[0][1]).toMatchObject({ search: 'ann' });
    });

    it('skips the visit when the term is the one the table already shows', async () => {
        const { type } = harness('ann');

        await type('ann');
        await type(' ann ');

        expect(routerGet).not.toHaveBeenCalled();
    });

    it('does not skip typing back to the shown term while another is on its way', async () => {
        const { type, table } = harness('ann');

        await type('anna');
        expect(routerGet).toHaveBeenCalledTimes(1);

        // Typed back before the reload for "anna" landed: it must still go out.
        await type('ann');
        expect(routerGet).toHaveBeenCalledTimes(2);

        // The table reports the search it now shows: the memory is dropped.
        table.state.search = 'ann';
        await vi.advanceTimersByTimeAsync(0);
        await type('ann');
        expect(routerGet).toHaveBeenCalledTimes(2);
    });

    it('waits for searchMinLength characters, but always reloads on clear', async () => {
        const { type, table } = harness('', 3);

        await type('an');
        expect(routerGet).not.toHaveBeenCalled();

        await type('ann');
        expect(routerGet).toHaveBeenCalledTimes(1);

        table.state.search = 'ann';
        await vi.advanceTimersByTimeAsync(0);
        await type('');
        expect(routerGet).toHaveBeenCalledTimes(2);
        expect(routerGet.mock.calls[1][1]).toMatchObject({ search: '' });
    });

    it('forgets the term on a failed or cancelled visit, so it can be sent again', async () => {
        const { type } = harness('');

        await type('ann');
        expect(routerGet).toHaveBeenCalledTimes(1);

        // The same term while the visit is in flight: nothing new to send.
        await type('ann');
        expect(routerGet).toHaveBeenCalledTimes(1);

        // The visit failed (onError) without the table ever reporting it.
        routerGet.mock.calls[0][2].onError();
        await type('ann');
        expect(routerGet).toHaveBeenCalledTimes(2);

        // An older visit finishing must not forget a newer one's term.
        const older = routerGet.mock.calls[1][2].onFinish;
        await type('anna');
        older();
        await type('anna');
        expect(routerGet).toHaveBeenCalledTimes(3);
    });

    it('treats 0 (or an older server) as every keystroke', async () => {
        const { type } = harness('', undefined);

        await type('a');

        expect(routerGet).toHaveBeenCalledTimes(1);
    });
});
