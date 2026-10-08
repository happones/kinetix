import { describe, expect, it } from 'vitest';
import { placeCard } from '@/components/Kanban/kanbanDropIndex';

const a = { id: 1 };
const b = { id: 2 };
const c = { id: 3 };
const x = { id: 9 };

describe('placeCard', () => {
    it('inserts a card from another column at the slot', () => {
        expect(placeCard([a, b, c], x, 0)).toEqual([x, a, b, c]);
        expect(placeCard([a, b, c], x, 2)).toEqual([a, b, x, c]);
        expect(placeCard([a, b, c], x, 3)).toEqual([a, b, c, x]);
        expect(placeCard([], x, 0)).toEqual([x]);
    });

    it('counts slots before the card leaves its own column', () => {
        expect(placeCard([a, b, c], a, 2)).toEqual([b, a, c]);
        expect(placeCard([a, b, c], a, 3)).toEqual([b, c, a]);
        expect(placeCard([a, b, c], c, 0)).toEqual([c, a, b]);
    });

    it('is null when the card would land where it is', () => {
        expect(placeCard([a, b, c], b, 1)).toBeNull();
        expect(placeCard([a, b, c], b, 2)).toBeNull();
        // Past either end of a column the card already ends or starts.
        expect(placeCard([a, b, c], c, 5)).toBeNull();
        expect(placeCard([a, b, c], a, -1)).toBeNull();
    });

    it('clamps a slot past either end', () => {
        expect(placeCard([a, b], x, 10)).toEqual([a, b, x]);
        expect(placeCard([a, b], x, -3)).toEqual([x, a, b]);
    });
});
