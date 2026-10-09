import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({
    router: { visit: vi.fn(), get: vi.fn(), reload: vi.fn() },
}));

const fetchMock = vi.hoisted(() => vi.fn(async () => ({})));

vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: fetchMock,
}));

import { useKinetixRowSelection } from '@/composables/useKinetixRowSelection';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    messages: { en: { kinetix: { action_failed: 'Failed' } } },
});

const record = (id: number) => ({ id }) as any;
const records = [record(1), record(2), record(3)];

// useI18n() must run inside a component setup, so exercise the composable inside
// a mounted harness rather than calling it bare.
const mountSelection = (descriptor?: () => string) => {
    let api: ReturnType<typeof useKinetixRowSelection>;

    const Harness = defineComponent({
        setup() {
            api = useKinetixRowSelection(
                () => records,
                descriptor
                    ? { descriptor, routePrefix: () => '_kinetix' }
                    : undefined,
            );

            return () => h('div');
        },
    });

    mount(Harness, { global: { plugins: [i18n] } });

    return api!;
};

describe('useKinetixRowSelection', () => {
    it('toggles individual rows and tracks the count', () => {
        const s = mountSelection();

        s.toggleRow(1, true);
        s.toggleRow(2, true);
        expect(s.selectionCount.value).toBe(2);
        expect(s.isRowSelected(1)).toBe(true);

        s.toggleRow(1, false);
        expect(s.selectionCount.value).toBe(1);
        expect(s.isRowSelected(1)).toBe(false);
    });

    it('selects and clears all rows on the page', () => {
        const s = mountSelection();

        s.toggleAllOnPage(true);
        expect(s.allOnPageSelected.value).toBe(true);
        expect(s.selectionCount.value).toBe(3);

        s.toggleAllOnPage(false);
        expect(s.allOnPageSelected.value).toBe(false);
        expect(s.selectionCount.value).toBe(0);
    });

    it('gates a confirmation-required bulk action behind the modal', async () => {
        const s = mountSelection();
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

        await s.onBulkConfirm();
        // Only closed + cleared after the action resolves.
        expect(s.isBulkConfirmOpen.value).toBe(false);
        expect(s.bulkPending.value).toBeNull();
        expect(s.selectionCount.value).toBe(0);
    });

    it('runs a non-confirming bulk action immediately and clears selection', async () => {
        const s = mountSelection();
        s.toggleRow(1, true);
        s.toggleRow(2, true);

        s.requestBulkAction({
            requiresConfirmation: false,
            dispatchEvent: 'noop',
        } as any);

        await flushPromises();
        expect(s.isBulkConfirmOpen.value).toBe(false);
        expect(s.selectionCount.value).toBe(0);
    });

    it('sends the descriptors of the pages rows were selected on', async () => {
        let page = 'page-1';
        const s = mountSelection(() => page);
        const action = {
            name: 'archive',
            isSecureBulk: true,
            requiresConfirmation: false,
        } as any;

        s.toggleRow(1, true);
        page = 'page-2';
        s.toggleRow(2, true);
        s.requestBulkAction(action);
        await flushPromises();

        expect(fetchMock).toHaveBeenLastCalledWith(
            '/_kinetix/tables/bulk-action',
            expect.objectContaining({
                body: {
                    descriptor: 'page-2',
                    descriptors: ['page-1'],
                    action: 'archive',
                    ids: [1, 2],
                },
            }),
        );

        // A run clears the selection, and the pages it was made on with it.
        s.toggleRow(3, true);
        s.requestBulkAction(action);
        await flushPromises();

        expect(fetchMock).toHaveBeenLastCalledWith(
            '/_kinetix/tables/bulk-action',
            expect.objectContaining({
                body: expect.objectContaining({ descriptors: [], ids: [3] }),
            }),
        );
    });
});
