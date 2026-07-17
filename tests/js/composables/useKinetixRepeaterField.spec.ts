import { describe, expect, it, vi } from 'vitest';
import {
    buildBlankItem,
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
