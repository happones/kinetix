import { computed, ref, type ComputedRef, type Ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import { executeAction } from '@/composables/useKinetixActions';
import type { KinetixAction, KinetixTableRecord } from '@/types';

/**
 * Row selection plus bulk-action orchestration for a Kinetix table. Tracks the
 * selected row ids, drives the "select all on page" checkbox, and gates
 * confirmation-required bulk actions behind a modal before dispatching them.
 *
 * Bulk actions send the selected ids to the server and clear the selection once
 * fired, mirroring the record-action confirmation flow.
 */
export interface UseKinetixRowSelection {
    selectedIds: Ref<Set<string | number>>;
    selectionCount: ComputedRef<number>;
    allOnPageSelected: ComputedRef<boolean>;
    isRowSelected: (id: string | number) => boolean;
    toggleRow: (id: string | number, checked: boolean) => void;
    toggleAllOnPage: (checked: boolean) => void;
    clearSelection: () => void;
    bulkPending: Ref<KinetixAction | null>;
    isBulkConfirmOpen: Ref<boolean>;
    bulkProcessing: Ref<boolean>;
    requestBulkAction: (action: KinetixAction) => void;
    onBulkConfirm: () => void;
    onBulkCancel: () => void;
}

export function useKinetixRowSelection(
    records: () => KinetixTableRecord[],
): UseKinetixRowSelection {
    const { t } = useI18n();
    const selectedIds = ref<Set<string | number>>(new Set());
    const selectionCount = computed<number>(() => selectedIds.value.size);

    const isRowSelected = (id: string | number): boolean =>
        selectedIds.value.has(id);

    const toggleRow = (id: string | number, checked: boolean): void => {
        const next = new Set(selectedIds.value);

        if (checked) {
            next.add(id);
        } else {
            next.delete(id);
        }

        selectedIds.value = next;
    };

    const allOnPageSelected = computed<boolean>(() => {
        const rows = records();

        return (
            rows.length > 0 && rows.every((r) => selectedIds.value.has(r.id))
        );
    });

    const toggleAllOnPage = (checked: boolean): void => {
        const next = new Set(selectedIds.value);
        records().forEach((r) =>
            checked ? next.add(r.id) : next.delete(r.id),
        );
        selectedIds.value = next;
    };

    const clearSelection = (): void => {
        selectedIds.value = new Set();
    };

    // Bulk actions dispatch the selected ids; destructive ones gate on a modal.
    const bulkPending = ref<KinetixAction | null>(null);
    const isBulkConfirmOpen = ref(false);
    const bulkProcessing = ref(false);

    const runBulkAction = async (action: KinetixAction): Promise<void> => {
        if (bulkProcessing.value) {
            return;
        }

        bulkProcessing.value = true;

        try {
            await executeAction(action, { ids: Array.from(selectedIds.value) });
            clearSelection();
        } catch (e) {
            toast.error(
                e instanceof Error && e.message
                    ? e.message
                    : t('kinetix.action_failed'),
            );
        } finally {
            bulkProcessing.value = false;
        }
    };

    const requestBulkAction = (action: KinetixAction): void => {
        if (bulkProcessing.value) {
            return;
        }

        if (action.requiresConfirmation) {
            bulkPending.value = action;
            isBulkConfirmOpen.value = true;

            return;
        }

        void runBulkAction(action);
    };

    const onBulkConfirm = async (): Promise<void> => {
        const action = bulkPending.value;

        if (bulkProcessing.value || !action) {
            return;
        }

        await runBulkAction(action);

        // Close only after the request resolves (modal shows its pending state).
        isBulkConfirmOpen.value = false;
        bulkPending.value = null;
    };

    const onBulkCancel = (): void => {
        if (bulkProcessing.value) {
            return;
        }

        isBulkConfirmOpen.value = false;
        bulkPending.value = null;
    };

    return {
        selectedIds,
        selectionCount,
        allOnPageSelected,
        isRowSelected,
        toggleRow,
        toggleAllOnPage,
        clearSelection,
        bulkPending,
        isBulkConfirmOpen,
        bulkProcessing,
        requestBulkAction,
        onBulkConfirm,
        onBulkCancel,
    };
}
