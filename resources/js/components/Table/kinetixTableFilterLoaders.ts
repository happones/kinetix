import {
    defineKinetixLazy,
    preloadKinetixLazy,
} from '@/composables/kinetixLazy';
import type { KinetixLazyComponent } from '@/composables/kinetixLazy';
import type { KinetixTableFilter } from '@/types/kinetix';
import { preloadKinetixFormFields } from '../Form/kinetixFormFieldLoaders';

/**
 * The table filter inputs, keyed by filter `type`. Every one downloads on
 * first use: a table page shouldn't pay for comboboxes, calendars and pickers
 * until someone opens its filters (the popover preloads them on intent).
 *
 * This module imports no component statically, on purpose.
 */
const selectFilter = defineKinetixLazy(
    () => import('./filters/FilterSelectField.vue'),
);
const dateFilter = defineKinetixLazy(
    () => import('./filters/FilterDateField.vue'),
);
const periodFilter = defineKinetixLazy(
    () => import('./filters/FilterPeriodField.vue'),
);

export const KINETIX_TABLE_FILTER_LOADERS: Record<
    KinetixTableFilter['type'],
    KinetixLazyComponent
> = {
    select: selectFilter,
    ternary: selectFilter,
    checkbox: defineKinetixLazy(
        () => import('./filters/FilterCheckboxField.vue'),
    ),
    'number-range': defineKinetixLazy(
        () => import('./filters/FilterNumberRangeField.vue'),
    ),
    'multi-select': defineKinetixLazy(
        () => import('./filters/FilterMultiSelectField.vue'),
    ),
    date: dateFilter,
    datetime: dateFilter,
    'date-range': defineKinetixLazy(
        () => import('./filters/FilterDateRangeField.vue'),
    ),
    month: periodFilter,
    year: periodFilter,
    week: periodFilter,
    address: defineKinetixLazy(
        () => import('./filters/FilterAddressField.vue'),
    ),
    form: defineKinetixLazy(() => import('./filters/FilterFormField.vue')),
};

/**
 * Start downloading every lazy filter input the given filters use, in
 * parallel — a form filter's own field types included. Call it on intent
 * (the filters trigger is hovered, focused or opened), not on page load.
 */
export function preloadKinetixTableFilters(
    filters:
        | ReadonlyArray<{ type: string; schema?: unknown }>
        | null
        | undefined,
): Promise<void> {
    const entries = new Set<KinetixLazyComponent>();
    const formSchemas: unknown[] = [];

    for (const filter of filters ?? []) {
        const entry = (
            KINETIX_TABLE_FILTER_LOADERS as Record<string, KinetixLazyComponent>
        )[filter.type];

        if (entry) {
            entries.add(entry);
        }

        if (filter.type === 'form' && Array.isArray(filter.schema)) {
            formSchemas.push(...filter.schema);
        }
    }

    return Promise.all([
        preloadKinetixLazy(entries),
        formSchemas.length > 0
            ? preloadKinetixFormFields(formSchemas)
            : Promise.resolve(),
    ]).then(() => undefined);
}
