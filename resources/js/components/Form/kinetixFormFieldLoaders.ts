import {
    defineKinetixLazy,
    KinetixLazyBlockSkeleton,
    preloadKinetixLazy,
} from '@/composables/kinetixLazy';
import type { KinetixLazyComponent } from '@/composables/kinetixLazy';

/**
 * The form field types whose code downloads on first use, keyed by schema
 * `type` (plus the `wizard` layout). The core types every form uses (text
 * input, select, checkbox, toggle, textarea, hidden) stay in the form's own
 * chunk; everything here only loads on a page whose form actually has it.
 *
 * This module imports no component statically, on purpose: importing it costs
 * a page nothing but the map.
 */
const periodPicker = defineKinetixLazy(
    () => import('./fields/PeriodPickerField.vue'),
);

export const KINETIX_FORM_FIELD_LOADERS: Record<string, KinetixLazyComponent> =
    {
        'date-picker': defineKinetixLazy(
            () => import('./fields/DatePickerField.vue'),
        ),
        'datetime-picker': defineKinetixLazy(
            () => import('./fields/DateTimePickerField.vue'),
        ),
        'time-picker': defineKinetixLazy(
            () => import('./fields/TimePickerField.vue'),
        ),
        'month-picker': periodPicker,
        'year-picker': periodPicker,
        'week-picker': periodPicker,
        'color-picker': defineKinetixLazy(
            () => import('./fields/ColorPickerField.vue'),
        ),
        'table-repeater': defineKinetixLazy(
            () => import('./fields/TableRepeaterField.vue'),
        ),
        'phone-input': defineKinetixLazy(
            () => import('../KinetixPhoneInput.vue'),
        ),
        'slug-input': defineKinetixLazy(
            () => import('../KinetixSlugInput.vue'),
        ),
        'generator-input': defineKinetixLazy(
            () => import('../KinetixGenerator.vue'),
        ),
        'json-input': defineKinetixLazy(
            () => import('../KinetixJsonInput.vue'),
        ),
        'signature-pad': defineKinetixLazy(
            () => import('../KinetixSignaturePad.vue'),
        ),
        slider: defineKinetixLazy(() => import('../KinetixSlider.vue')),
        rating: defineKinetixLazy(() => import('../KinetixRating.vue')),
        'pin-input': defineKinetixLazy(() => import('../KinetixPinInput.vue')),
        'number-field': defineKinetixLazy(
            () => import('../KinetixNumberField.vue'),
        ),
        'rich-editor': defineKinetixLazy(
            () => import('../KinetixRichEditor.vue'),
        ),
        'business-hours': defineKinetixLazy(
            () => import('../KinetixBusinessHours.vue'),
        ),
        'address-picker': defineKinetixLazy(
            () => import('../KinetixAddressPicker.vue'),
        ),
        'date-range-picker': defineKinetixLazy(
            () => import('../KinetixDateRangePicker.vue'),
        ),
        radio: defineKinetixLazy(() => import('../KinetixRadioGroup.vue')),
        'checkbox-list': defineKinetixLazy(
            () => import('../KinetixCheckboxList.vue'),
        ),
        'tags-input': defineKinetixLazy(
            () => import('../KinetixTagsInput.vue'),
        ),
        'key-value': defineKinetixLazy(() => import('../KinetixKeyValue.vue')),
        'file-upload': defineKinetixLazy(
            () => import('../KinetixFileUpload.vue'),
        ),
        'media-library': defineKinetixLazy(
            () => import('../KinetixMediaLibrary.vue'),
        ),
        // A whole multi-step layout: the block skeleton holds its place.
        wizard: defineKinetixLazy(() => import('../KinetixFormWizard.vue'), {
            loading: KinetixLazyBlockSkeleton,
        }),
    };

/**
 * The two controls behind a `select` field. The field type itself ships with
 * the form; only the control it renders downloads: the combobox for a
 * searchable select, the plain select otherwise.
 */
export const KINETIX_SELECT_CONTROL_LOADERS = {
    combobox: defineKinetixLazy(() => import('../KinetixCombobox.vue')),
    select: defineKinetixLazy(() => import('../KinetixSelect.vue')),
} as const;

/** The lazy entries a schema needs, walking every nested layout once. */
export function kinetixFormFieldLoadersFor(
    schema: unknown,
): Set<KinetixLazyComponent> {
    const needed = new Set<KinetixLazyComponent>();
    const visited = new WeakSet<object>();

    const walk = (nodes: unknown): void => {
        if (!Array.isArray(nodes) || visited.has(nodes)) {
            return;
        }

        visited.add(nodes);

        for (const node of nodes) {
            if (!node || typeof node !== 'object') {
                continue;
            }

            const { type, schema: children } = node as {
                type?: unknown;
                schema?: unknown;
            };

            const entry =
                typeof type === 'string'
                    ? KINETIX_FORM_FIELD_LOADERS[type]
                    : undefined;

            if (entry) {
                needed.add(entry);
            }

            if (type === 'select') {
                needed.add(
                    (node as { isSearchable?: unknown }).isSearchable
                        ? KINETIX_SELECT_CONTROL_LOADERS.combobox
                        : KINETIX_SELECT_CONTROL_LOADERS.select,
                );
            }

            walk(children);
        }
    };

    walk(schema);

    return needed;
}

/**
 * Start downloading every lazy field type a schema uses, all in parallel, so a
 * form with a date picker and a rich editor fetches both at once instead of
 * discovering them one render at a time. Safe to call repeatedly; a schema
 * made of core types only loads nothing.
 */
export function preloadKinetixFormFields(schema: unknown[]): Promise<void> {
    return preloadKinetixLazy(kinetixFormFieldLoadersFor(schema));
}
