import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({
    router: { visit: vi.fn(), get: vi.fn(), reload: vi.fn() },
}));

import { useKinetixRowSelection } from '@/composables/useKinetixRowSelection';

const record = (id: number) => ({ id }) as any;

const records = [record(1), record(2), record(3)];

describe('useKinetixRowSelection', () => {
    it('toggles individual rows and tracks the count', () => {
        const s = useKinetixRowSelection(() => records);

        s.toggleRow(1, true);
        s.toggleRow(2, true);
        expect(s.selectionCount.value).toBe(2);
        expect(s.isRowSelected(1)).toBe(true);

        s.toggleRow(1, false);
        expect(s.selectionCount.value).toBe(1);
        expect(s.isRowSelected(1)).toBe(false);
    });

    it('selects and clears all rows on the page', () => {
        const s = useKinetixRowSelection(() => records);

        s.toggleAllOnPage(true);
        expect(s.allOnPageSelected.value).toBe(true);
        expect(s.selectionCount.value).toBe(3);

        s.toggleAllOnPage(false);
        expect(s.allOnPageSelected.value).toBe(false);
        expect(s.selectionCount.value).toBe(0);
    });

    it('gates a confirmation-required bulk action behind the modal', () => {
        const s = useKinetixRowSelection(() => records);
        s.toggleRow(1, true);

        const action = {
            requiresConfirmation: true,
            dispatchEvent: 'noop',
        } as any;
        s.requestBulkAction(action);

        expect(s.isBulkConfirmOpen.value).toBe(true);
        expect(s.bulkPending.value).toEqual(action);
        // Selection is retained until the action actually runs.
        expect(s.selectionCount.value).toBe(1);

        s.onBulkConfirm();
        expect(s.bulkPending.value).toBeNull();
        // Running the action clears the selection.
        expect(s.selectionCount.value).toBe(0);
    });

    it('runs a non-confirming bulk action immediately and clears selection', () => {
        const s = useKinetixRowSelection(() => records);
        s.toggleRow(1, true);
        s.toggleRow(2, true);

        s.requestBulkAction({
            requiresConfirmation: false,
            dispatchEvent: 'noop',
        } as any);

        expect(s.isBulkConfirmOpen.value).toBe(false);
        expect(s.selectionCount.value).toBe(0);
    });
});
