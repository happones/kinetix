import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import { useKinetixTableGroups } from '@/composables/useKinetixTableGroups';
import type { KinetixTableData, KinetixTableRecord } from '@/types/kinetix';

const record = (
    id: number,
    groupKey: string | null,
    groupLabel: string | null,
): KinetixTableRecord => ({
    id,
    values: {},
    actions: [],
    descriptions: {},
    icons: {},
    iconColors: {},
    badgeColors: {},
    progress: {},
    progressColors: {},
    viewProps: {},
    recordUrl: null,
    groupKey,
    groupLabel,
});

const table = (overrides: Partial<KinetixTableData> = {}): KinetixTableData =>
    ({
        groups: [],
        defaultGroup: null,
        ...overrides,
    }) as KinetixTableData;

const mountGroups = (tbl: KinetixTableData, rows: KinetixTableRecord[]) => {
    let api: ReturnType<typeof useKinetixTableGroups>;

    const Harness = defineComponent({
        setup() {
            api = useKinetixTableGroups(
                () => tbl,
                () => rows,
            );

            return () => h('div');
        },
    });

    const wrapper = mount(Harness);

    return { wrapper, api: api! };
};

describe('useKinetixTableGroups', () => {
    it('is ungrouped when no group is active', () => {
        const { api } = mountGroups(table(), [
            record(1, null, null),
            record(2, null, null),
        ]);

        expect(api.isGrouped.value).toBe(false);
        // Ungrouped renderItems is a flat list of rows (no headers).
        expect(api.renderItems.value).toHaveLength(2);
        expect(api.renderItems.value.every((i) => i.type === 'row')).toBe(true);
    });

    it('slices contiguous rows into group sections with a header each', () => {
        const tbl = table({
            groups: [{ column: 'status', label: 'Status', collapsible: true }],
            defaultGroup: 'status',
        });
        const rows = [
            record(1, 'open', 'Open'),
            record(2, 'open', 'Open'),
            record(3, 'done', 'Done'),
        ];

        const { api } = mountGroups(tbl, rows);

        expect(api.isGrouped.value).toBe(true);
        expect(api.sections.value).toHaveLength(2);
        expect(api.sections.value[0].key).toBe('open');
        expect(api.sections.value[0].rows).toHaveLength(2);
        expect(api.sections.value[1].key).toBe('done');

        // renderItems interleaves one header before each run's rows.
        const items = api.renderItems.value;
        expect(items[0]).toMatchObject({
            type: 'header',
            key: 'open',
            count: 2,
        });
        expect(items[1]).toMatchObject({ type: 'row' });
        expect(items[2]).toMatchObject({ type: 'row' });
        expect(items[3]).toMatchObject({
            type: 'header',
            key: 'done',
            count: 1,
        });
    });

    it("hides a collapsed group's rows but keeps its header", async () => {
        const tbl = table({
            groups: [{ column: 'status', label: 'Status', collapsible: true }],
            defaultGroup: 'status',
        });
        const rows = [record(1, 'open', 'Open'), record(2, 'done', 'Done')];

        const { api } = mountGroups(tbl, rows);

        api.toggleGroup('open');
        await nextTick();

        expect(api.isCollapsed('open')).toBe(true);

        const items = api.renderItems.value;
        // 2 headers + 1 visible row (the collapsed group's row is dropped).
        expect(items.filter((i) => i.type === 'header')).toHaveLength(2);
        expect(items.filter((i) => i.type === 'row')).toHaveLength(1);
        expect(items.find((i) => i.type === 'row')).toMatchObject({
            key: 'done',
        });
    });

    it('buckets null group values under one stable section', () => {
        const tbl = table({
            groups: [{ column: 'status', label: 'Status', collapsible: false }],
            defaultGroup: 'status',
        });
        const rows = [record(1, null, null), record(2, null, null)];

        const { api } = mountGroups(tbl, rows);

        expect(api.sections.value).toHaveLength(1);
        expect(api.sections.value[0].rows).toHaveLength(2);
    });
});
