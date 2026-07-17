import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick, ref } from 'vue';

const fetchMock = vi.fn().mockResolvedValue({});
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
}));

import {
    moveArrayItem,
    useKinetixTableReorder,
} from '@/composables/useKinetixTableReorder';

describe('moveArrayItem', () => {
    it('moves an item forward', () => {
        expect(moveArrayItem(['a', 'b', 'c', 'd'], 0, 2)).toEqual([
            'b',
            'c',
            'a',
            'd',
        ]);
    });

    it('moves an item backward', () => {
        expect(moveArrayItem(['a', 'b', 'c', 'd'], 3, 1)).toEqual([
            'a',
            'd',
            'b',
            'c',
        ]);
    });
});

const rec = (id: number) => ({ id }) as any;

describe('useKinetixTableReorder', () => {
    it('reorders the local copy on dragover and persists ids on drop', async () => {
        const source = ref([rec(1), rec(2), rec(3)]);
        let api: ReturnType<typeof useKinetixTableReorder>;

        const Harness = defineComponent({
            setup() {
                api = useKinetixTableReorder({
                    records: () => source.value,
                    reorderable: () => true,
                    model: () => 'token',
                    routePrefix: () => '_kinetix',
                });

                return () => h('div');
            },
        });

        mount(Harness);
        await nextTick();

        api!.onDragStart(0);
        api!.onDragOver(2, { preventDefault: vi.fn() } as any);
        expect(api!.rows.value.map((r) => r.id)).toEqual([2, 3, 1]);

        await api!.onDrop();
        expect(fetchMock).toHaveBeenCalledWith(
            '/_kinetix/tables/reorder',
            expect.objectContaining({
                method: 'POST',
                body: { model: 'token', ids: [2, 3, 1] },
            }),
        );
    });

    it('re-syncs the local copy when the server ships a fresh row set', async () => {
        const source = ref([rec(1), rec(2)]);
        let api: ReturnType<typeof useKinetixTableReorder>;

        const Harness = defineComponent({
            setup() {
                api = useKinetixTableReorder({
                    records: () => source.value,
                    reorderable: () => true,
                    model: () => 'token',
                    routePrefix: () => '_kinetix',
                });

                return () => h('div');
            },
        });

        mount(Harness);
        await nextTick();

        source.value = [rec(9), rec(8), rec(7)];
        await nextTick();
        expect(api!.rows.value.map((r) => r.id)).toEqual([9, 8, 7]);
    });
});
