import { preloadKinetixLazy } from '@/composables/kinetixLazy';
import {
    KINETIX_FORM_FIELD_LOADERS,
    KINETIX_SELECT_CONTROL_LOADERS,
} from '@/components/Form/kinetixFormFieldLoaders';
import {
    KINETIX_LAZY_FORM,
    KINETIX_LAZY_INFOLIST,
} from '@/components/kinetixModalBodyLoaders';
import { KINETIX_TABLE_CELL_LOADERS } from '@/components/Table/kinetixTableCellLoaders';
import { KINETIX_TABLE_FILTER_LOADERS } from '@/components/Table/kinetixTableFilterLoaders';

/**
 * Download every lazy form field, table filter, input cell and modal body up front, so a
 * spec can mount synchronously and find the real components — call it from
 * `beforeAll`. Specs about the loading itself must not use it.
 */
export async function preloadAllKinetixLazy(): Promise<void> {
    const entries = [
        ...Object.values(KINETIX_FORM_FIELD_LOADERS),
        ...Object.values(KINETIX_SELECT_CONTROL_LOADERS),
        ...Object.values(KINETIX_TABLE_FILTER_LOADERS),
        ...Object.values(KINETIX_TABLE_CELL_LOADERS),
        KINETIX_LAZY_FORM,
        KINETIX_LAZY_INFOLIST,
    ];

    await Promise.all(entries.map((entry) => entry.preload()));
}
