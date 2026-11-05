import {
    defineKinetixLazy,
    preloadKinetixLazy,
} from '@/composables/kinetixLazy';
import type { KinetixLazyComponent } from '@/composables/kinetixLazy';

/**
 * The editable (input) cells, keyed by column `type`. Most tables only
 * display, so the inputs and what they carry (number parsing, comboboxes)
 * download only for a table that has such a column; the display cells stay
 * in the table's chunk.
 *
 * This module imports no component statically, on purpose.
 */
export const KINETIX_TABLE_CELL_LOADERS: Record<string, KinetixLazyComponent> =
    {
        'select-input': defineKinetixLazy(
            () => import('./cells/SelectInputCell.vue'),
        ),
        'toggle-input': defineKinetixLazy(
            () => import('./cells/ToggleInputCell.vue'),
        ),
        'text-input': defineKinetixLazy(
            () => import('./cells/TextInputCell.vue'),
        ),
        'number-input': defineKinetixLazy(
            () => import('./cells/NumberInputCell.vue'),
        ),
        'checkbox-input': defineKinetixLazy(
            () => import('./cells/CheckboxInputCell.vue'),
        ),
    };

/**
 * Start downloading the input cells the given columns use, in parallel. Rows
 * render right away, so call it when the table is set up (and again when its
 * columns change); a table without input columns loads nothing.
 */
export function preloadKinetixTableCells(
    columns: ReadonlyArray<{ type: string }> | null | undefined,
): Promise<void> {
    return preloadKinetixLazy(
        (columns ?? []).map(
            (column) => KINETIX_TABLE_CELL_LOADERS[column.type],
        ),
    );
}
