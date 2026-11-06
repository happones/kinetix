import { describe, expect, it, vi } from 'vitest';
import {
    buildBlankItem,
    KINETIX_ROW_KEY,
    useKinetixRepeater,
} from '@/composables/useKinetixRepeaterField';

describe('buildBlankItem', () => {
    it('seeds each named field from its default, recursing layouts', () => {
        const blank = buildBlankItem([
            { type: 'text-input', name: 'title', defaultValue: 'x' },
            {
                type: 'grid',
                schema: [
                    { type: 'number-field', name: 'qty', defaultValue: 1 },
                    { type: 'text-input', name: 'note' },
                ],
            },
        ]);

        expect(blank).toEqual({ title: 'x', qty: 1, note: null });
    });
});

describe('useKinetixRepeater', () => {
    const setup = (initial: Record<string, any>[]) => {
        const state = { list: initial };
        const emit = vi.fn((name: string, value: any[]) => {
            (state as any)[name] = value;
        });
        const repeater = useKinetixRepeater({
            values: () => state,
            emit,
        });

        return { state, emit, repeater };
    };

    it('adds a blank item built from the schema', () => {
        const { emit, repeater } = setup([]);
        repeater.addItem('list', [{ name: 'a', defaultValue: 5 }]);

        expect(emit).toHaveBeenCalledWith('list', [{ a: 5 }]);
    });

    it('removes an item by index', () => {
        const { emit, repeater } = setup([{ a: 1 }, { a: 2 }]);
        repeater.removeItem('list', 0);

        expect(emit).toHaveBeenCalledWith('list', [{ a: 2 }]);
    });

    it('moves an item and is a no-op past the bounds', () => {
        const { emit, repeater } = setup([{ a: 1 }, { a: 2 }]);
        repeater.moveItem('list', 0, 1);
        expect(emit).toHaveBeenLastCalledWith('list', [{ a: 2 }, { a: 1 }]);

        emit.mockClear();
        repeater.moveItem('list', 0, -1); // out of bounds
        expect(emit).not.toHaveBeenCalled();
    });

    it('updates a single field within an item', () => {
        const { emit, repeater } = setup([{ a: 1, b: 2 }]);
        repeater.updateItem('list', 0, 'b', 9);

        expect(emit).toHaveBeenCalledWith('list', [{ a: 1, b: 9 }]);
    });
});

describe('server row keys', () => {
    const keyed = () => [
        { name: 'a', price: 10, [KINETIX_ROW_KEY]: '0.AAAA' },
        { name: 'b', price: 20, [KINETIX_ROW_KEY]: '1.BBBB' },
    ];

    const setup = () => {
        const state: Record<string, any> = { items: keyed() };
        const repeater = useKinetixRepeater({
            values: () => state,
            emit: (name, value) => {
                state[name] = value;
            },
        });

        return { state, repeater };
    };

    it('is the key the server issues', () => {
        expect(KINETIX_ROW_KEY).toBe('__kinetix_row');
    });

    it('stays on its row through update, move and remove', () => {
        const { state, repeater } = setup();

        repeater.updateItem('items', 0, 'name', 'A');
        expect(state.items[0]).toEqual({
            name: 'A',
            price: 10,
            [KINETIX_ROW_KEY]: '0.AAAA',
        });

        repeater.moveItem('items', 0, 1);
        expect(state.items.map((r: any) => r[KINETIX_ROW_KEY])).toEqual([
            '1.BBBB',
            '0.AAAA',
        ]);

        repeater.removeItem('items', 0);
        expect(state.items).toEqual([
            { name: 'A', price: 10, [KINETIX_ROW_KEY]: '0.AAAA' },
        ]);
    });

    it('never puts a key on an added row', () => {
        const { state, repeater } = setup();

        repeater.addItem('items', [
            { name: 'name' },
            { name: 'price', defaultValue: 0 },
            // Even a schema node named like the key does not seed one.
            { name: KINETIX_ROW_KEY, defaultValue: '0.AAAA' },
        ]);

        const added = state.items[2];
        expect(added).toEqual({ name: null, price: 0 });
        expect(KINETIX_ROW_KEY in added).toBe(false);
    });
});
