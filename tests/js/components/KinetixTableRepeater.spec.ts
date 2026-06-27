import { mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));

const createMock = vi.fn().mockResolvedValue(99);
const updateMock = vi.fn().mockResolvedValue(undefined);
const removeMock = vi.fn().mockResolvedValue(undefined);
vi.mock('@/composables/useKinetixTableRepeater', () => ({
    useKinetixTableRepeater: () => ({
        create: createMock,
        update: updateMock,
        remove: removeMock,
    }),
}));

// Stub the recursive schema renderer: one input per cell that emits update:value.
vi.mock('@/components/KinetixFormSchema.vue', () => ({
    default: defineComponent({
        props: ['schema', 'values', 'errors'],
        emits: ['update:value'],
        setup(props, { emit }) {
            return () =>
                h('input', {
                    class: 'cell',
                    value: props.values[props.schema[0].name],
                    onInput: (e: any) =>
                        emit(
                            'update:value',
                            props.schema[0].name,
                            e.target.value,
                        ),
                });
        },
    }),
}));

import KinetixTableRepeater from '@/components/KinetixTableRepeater.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    missing: (_l: string, key: string) => key,
    messages: {
        en: {
            kinetix: {
                add_item: 'Add item',
                remove: 'Remove',
                export: 'Export',
                table_repeater_empty: 'No rows yet.',
            },
        },
    },
});

const baseComp = {
    name: 'items',
    schema: [
        { name: 'name', label: 'Name', type: 'text-input' },
        { name: 'qty', label: 'Qty', type: 'number-field' },
    ],
    summarize: { qty: 'sum' },
    exportable: true,
};

const mountIt = (comp = baseComp, rows: any[] = []) =>
    mount(KinetixTableRepeater, {
        props: { comp, modelValue: rows, errors: {} },
        global: { plugins: [i18n] },
    });

beforeEach(() => {
    createMock.mockClear();
    updateMock.mockClear();
    removeMock.mockClear();
});

describe('KinetixTableRepeater', () => {
    it('renders column headers and a row per item', () => {
        const w = mountIt(baseComp, [{ name: 'A', qty: 2 }]);
        expect(w.text()).toContain('Name');
        expect(w.text()).toContain('Qty');
        expect(w.findAll('tbody tr')).toHaveLength(1);
    });

    it('shows the empty state with no rows', () => {
        expect(mountIt(baseComp, []).text()).toContain('No rows yet.');
    });

    it('adds a blank row on "Add item"', async () => {
        const w = mountIt(baseComp, [{ name: 'A', qty: 2 }]);
        await w
            .findAll('button')
            .find((b) => b.text().includes('Add item'))!
            .trigger('click');

        const events = w.emitted('update:modelValue');
        expect(events).toBeTruthy();
        expect((events!.at(-1)![0] as any[]).length).toBe(2);
    });

    it('removes a row', async () => {
        const w = mountIt(baseComp, [
            { name: 'A', qty: 2 },
            { name: 'B', qty: 3 },
        ]);
        await w.find('tbody tr [aria-label="Remove"]').trigger('click');

        const last = w.emitted('update:modelValue')!.at(-1)![0] as any[];
        expect(last.length).toBe(1);
        expect(last[0].name).toBe('B');
    });

    it('computes a column summary (sum)', () => {
        const w = mountIt(baseComp, [
            { name: 'A', qty: 2 },
            { name: 'B', qty: 3 },
        ]);
        expect(w.find('tfoot').text()).toContain('5');
    });

    it('autosaves a created row when a token is present', async () => {
        const comp = { ...baseComp, autosave: true, autosaveToken: 'tok' };
        const w = mountIt(comp, []);
        await w
            .findAll('button')
            .find((b) => b.text().includes('Add item'))!
            .trigger('click');

        expect(createMock).toHaveBeenCalledWith('tok', expect.any(Object));
    });
});
