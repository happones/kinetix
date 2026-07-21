import { computed, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import type { KinetixTableColumn } from '@/types';

/**
 * Local column-visibility state for a Kinetix table. Seeds the visible set from
 * each column's `isToggledHiddenByDefault` flag and exposes toggles plus the
 * filtered `columnsToRender` list the table iterates.
 *
 * At least one column always stays visible — toggling off the final visible
 * column is a no-op so the table never renders zero columns.
 */
export interface UseKinetixColumnVisibility {
    visibleColumnNames: Ref<Set<string>>;
    isColumnVisible: (name: string) => boolean;
    toggleColumn: (name: string) => void;
    columnsToRender: ComputedRef<KinetixTableColumn[]>;
}

export function useKinetixColumnVisibility(
    columns: () => KinetixTableColumn[],
): UseKinetixColumnVisibility {
    const visibleColumnNames = ref<Set<string>>(
        new Set(
            columns()
                .filter((c) => !c.isToggledHiddenByDefault)
                .map((c) => c.name),
        ),
    );

    const isColumnVisible = (name: string): boolean =>
        visibleColumnNames.value.has(name);

    const toggleColumn = (name: string): void => {
        const next = new Set(visibleColumnNames.value);

        if (next.has(name)) {
            // Keep at least one column on screen.
            if (next.size > 1) {
                next.delete(name);
                visibleColumnNames.value = next;
            }

            return;
        }

        next.add(name);
        visibleColumnNames.value = next;
    };

    const columnsToRender = computed<KinetixTableColumn[]>(() =>
        columns().filter((c) => isColumnVisible(c.name)),
    );

    return {
        visibleColumnNames,
        isColumnVisible,
        toggleColumn,
        columnsToRender,
    };
}
