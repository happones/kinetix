<script setup lang="ts">
import { computed } from 'vue';
import type { Component } from 'vue';
import { useI18n } from 'vue-i18n';
import { dateBounds } from '@/composables/kinetixFieldConstraints';
import { kinetixComponentOf } from '@/composables/kinetixLazy';
import type { KinetixLazyComponent } from '@/composables/kinetixLazy';
import CheckboxField from './fields/CheckboxField.vue';
import HiddenField from './fields/HiddenField.vue';
import SelectField from './fields/SelectField.vue';
import TextareaField from './fields/TextareaField.vue';
import TextInputField from './fields/TextInputField.vue';
import ToggleField from './fields/ToggleField.vue';
import { KINETIX_FORM_FIELD_LOADERS as LAZY } from './kinetixFormFieldLoaders';

const props = defineProps<{
    comp: any;
    values: Record<string, any>;
    errors: Record<string, string>;
    /** Another id on the describedby chain: the rules' hints. */
    describedBy?: string;
}>();

const emit = defineEmits<{ (e: 'update', value: any): void }>();

const { t } = useI18n();

/**
 * Field-type → component map. Resolving by lookup (O(1)) rather than a ~35-way
 * `v-if`/`v-else-if` chain keeps the render fn flat, avoids re-evaluating every
 * branch on each update, and makes adding a field type a one-line entry. Each
 * dedicated field shares the `{ comp, value } -> update` contract.
 *
 * Layout nodes and the recursive inline `repeater` are handled by
 * `KinetixFormSchema` directly (the repeater renders the schema recursively).
 *
 * Only the core types every form uses are imported here; the rest are lazy
 * entries (see kinetixFormFieldLoaders) that KinetixForm starts downloading as
 * soon as it sees them in the schema.
 */
const DEDICATED_FIELDS: Record<string, Component | KinetixLazyComponent> = {
    'text-input': TextInputField,
    select: SelectField,
    checkbox: CheckboxField,
    toggle: ToggleField,
    textarea: TextareaField,
    'date-picker': LAZY['date-picker'],
    'datetime-picker': LAZY['datetime-picker'],
    'time-picker': LAZY['time-picker'],
    'month-picker': LAZY['month-picker'],
    'year-picker': LAZY['year-picker'],
    'week-picker': LAZY['week-picker'],
    'color-picker': LAZY['color-picker'],
    hidden: HiddenField,
};

/**
 * Straight passthroughs to an existing Kinetix control: a `:value` +
 * `@update:value` component plus a per-type prop builder. Kept as map entries
 * rather than one wrapper file each.
 */
type Delegate = {
    component: Component | KinetixLazyComponent;
    props: (comp: any, values: Record<string, any>) => Record<string, unknown>;
};

const DELEGATE_FIELDS: Record<string, Delegate> = {
    'phone-input': {
        component: LAZY['phone-input'],
        props: (c) => ({
            id: c.name,
            config: c.phoneConfig,
            disabled: c.isDisabled,
            placeholder: c.placeholder,
        }),
    },
    'slug-input': {
        component: LAZY['slug-input'],
        props: (c, values) => ({
            id: c.name,
            source: c.slugConfig?.from ? values[c.slugConfig.from] : null,
            config: c.slugConfig,
            disabled: c.isDisabled,
            placeholder: c.placeholder,
        }),
    },
    'generator-input': {
        component: LAZY['generator-input'],
        props: (c, values) => ({
            id: c.name,
            config: c.generatorConfig,
            // Sibling values power a username `pattern` ({first}.{last}).
            values,
            disabled: c.isDisabled,
            placeholder: c.placeholder,
        }),
    },
    'json-input': {
        component: LAZY['json-input'],
        props: (c) => ({
            id: c.name,
            disabled: c.isDisabled,
            placeholder: c.placeholder,
            rows: Number(c.extraInputAttributes?.rows) || undefined,
        }),
    },
    'signature-pad': {
        component: LAZY['signature-pad'],
        props: (c) => ({ config: c.signatureConfig, disabled: c.isDisabled }),
    },
    slider: {
        component: LAZY['slider'],
        props: (c) => ({
            id: c.name,
            config: c.numberConfig,
            disabled: c.isDisabled,
        }),
    },
    rating: {
        component: LAZY['rating'],
        props: (c) => ({ config: c.ratingConfig, disabled: c.isDisabled }),
    },
    'pin-input': {
        component: LAZY['pin-input'],
        props: (c) => ({
            id: c.name,
            config: c.pinConfig,
            disabled: c.isDisabled,
        }),
    },
    'number-field': {
        component: LAZY['number-field'],
        props: (c) => ({
            id: c.name,
            config: c.numberConfig,
            disabled: c.isDisabled,
            placeholder: c.placeholder,
        }),
    },
    'rich-editor': {
        component: LAZY['rich-editor'],
        props: (c) => ({
            editor: c.editor,
            disabled: c.isDisabled,
            placeholder: c.placeholder,
        }),
    },
    'business-hours': {
        component: LAZY['business-hours'],
        props: (c) => ({
            disabled: c.isDisabled,
            hour12: c.hour12 ?? true,
            minuteStep: c.minuteStep ?? 5,
        }),
    },
    'address-picker': {
        component: LAZY['address-picker'],
        props: (c) => ({
            fields: c.addressFields,
            countries: c.options,
            disabled: c.isDisabled,
            locationsToken: c.addressLocations ?? null,
            required: c.addressRequired ?? [],
            neighborhoods: c.addressNeighborhoods ?? false,
            postalCodeLookup: c.addressPostalCodeLookup ?? false,
        }),
    },
    'date-range-picker': {
        component: LAZY['date-range-picker'],
        props: (c) => ({
            id: c.name,
            native: !c.useCalendar,
            disabled: c.isDisabled,
            placeholder: c.placeholder,
            locale: c.dateLocale,
            weekdayFormat: c.weekdayFormat,
            numberOfMonths: c.numberOfMonths,
            fixedWeeks: c.fixedWeeks,
            minValue: c.minValue,
            maxValue: c.maxValue,
            confirm: c.confirm,
            showToday: c.showToday,
            closeOnSelect: c.closeOnSelect ?? true,
            timezone: c.timezone,
        }),
    },
    radio: {
        component: LAZY['radio'],
        props: (c) => ({
            options: c.options,
            inline: c.isInline,
            disabled: c.isDisabled,
        }),
    },
    'checkbox-list': {
        component: LAZY['checkbox-list'],
        props: (c) => ({
            options: c.options,
            inline: c.isInline,
            bulkToggleable: c.isBulkToggleable,
            disabled: c.isDisabled,
            searchable: c.isSearchable,
            searchToken: c.searchToken,
        }),
    },
    'tags-input': {
        component: LAZY['tags-input'],
        props: (c) => ({
            id: c.name,
            disabled: c.isDisabled,
            placeholder: c.placeholder,
            ignoreCase: c.constraints?.distinct === 'ignore_case',
            maxItems: c.constraints?.items?.max ?? null,
        }),
    },
    'key-value': {
        component: LAZY['key-value'],
        props: (c) => ({
            id: c.name,
            disabled: c.isDisabled,
            keys: c.constraints?.keys ?? null,
        }),
    },
    'file-upload': {
        component: LAZY['file-upload'],
        props: (c) => ({
            uploadToken: c.uploadToken,
            isMultiple: c.isMultiple,
            acceptedFileTypes: c.acceptedFileTypes,
            maxSize: c.maxSize,
            isImage: c.isImage,
            maxFiles: c.maxFiles,
            disabled: c.isDisabled,
            constraints: c.constraints,
        }),
    },
    'media-library': {
        component: LAZY['media-library'],
        props: (c) => ({
            uploadToken: c.uploadToken,
            acceptedFileTypes: c.acceptedFileTypes,
            isImage: c.isImage,
            maxFiles: c.maxFiles,
            reorderable: c.isReorderable,
            disabled: c.isDisabled,
        }),
    },
};

/**
 * The field as its control gets it: a date field's picker bounded by what its
 * rules allow (`after:today`, `before:ends_on`), read against the current
 * values so a range's two ends keep each other in order.
 */
const DATE_TYPES = ['date-picker', 'datetime-picker'];

/**
 * A withheld field has a stored value the server never sends (a secret, an
 * encrypted setting): the control renders empty, so its placeholder says a value
 * exists and that leaving it blank keeps it. A field's own placeholder wins.
 */
const withheld = computed<boolean>(
    () => props.comp.isWithheld === true && props.comp.type !== 'hidden',
);

const baseComp = computed(() =>
    withheld.value && !props.comp.placeholder
        ? {
              ...props.comp,
              placeholder: t('kinetix.form_withheld_placeholder'),
          }
        : props.comp,
);

const fieldComp = computed(() => {
    const comp = baseComp.value;

    if (!DATE_TYPES.includes(comp.type) || !comp.constraints?.dates?.length) {
        return comp;
    }

    const { min, max } = dateBounds(
        comp.constraints,
        props.values,
        { min: comp.minValue, max: comp.maxValue },
        comp.type === 'datetime-picker',
    );

    return { ...comp, minValue: min, maxValue: max };
});

/**
 * Resolved from the TYPE alone: a lazy entry answers its loading wrapper until
 * its code arrives and the real component after, so re-resolving on every
 * `comp` change would swap the two mid-edit and remount the control. The type
 * computed only notifies when the type string itself changes.
 */
const fieldType = computed<string>(() => props.comp.type);

const tableRepeater = computed<Component | null>(() =>
    fieldType.value === 'table-repeater'
        ? kinetixComponentOf(LAZY['table-repeater'])
        : null,
);
const dedicated = computed<Component | null>(() => {
    const entry = DEDICATED_FIELDS[fieldType.value];

    return entry ? kinetixComponentOf(entry) : null;
});
const delegate = computed<Delegate | null>(
    () => DELEGATE_FIELDS[fieldType.value] ?? null,
);
const delegateComponent = computed<Component | null>(() =>
    delegate.value ? kinetixComponentOf(delegate.value.component) : null,
);

/**
 * Describedby/error wiring for assistive tech: `aria-describedby` chains the
 * helper text (`<name>-help`) and the error (`<name>-error`) ids that
 * KinetixFormSchema renders, plus the withheld hint (`<name>-withheld`) rendered
 * here, and `aria-invalid` flips the field into its
 * destructive state (the shadcn input/textarea classes style it). Bound via
 * fallthrough attrs so every field type gets it without per-field plumbing.
 */
const errorA11yAttrs = computed<Record<string, string | undefined>>(() => {
    const hasError = !!props.errors[props.comp.name];

    const describedBy = [
        props.comp.description ? `${props.comp.name}-help` : null,
        withheld.value ? `${props.comp.name}-withheld` : null,
        props.describedBy ?? null,
        hasError ? `${props.comp.name}-error` : null,
    ]
        .filter(Boolean)
        .join(' ');

    return {
        ...(hasError ? { 'aria-invalid': 'true' } : {}),
        ...(describedBy ? { 'aria-describedby': describedBy } : {}),
    };
});
</script>

<template>
    <!-- Repeater with nested table columns (self-contained). -->
    <component
        :is="tableRepeater"
        v-if="tableRepeater"
        :comp="comp"
        :value="values[comp.name]"
        :errors="errors"
        @update="emit('update', $event)"
    />

    <!-- Dedicated field components (custom markup / internal branching). -->
    <component
        :is="dedicated"
        v-else-if="dedicated"
        :comp="fieldComp"
        :value="values[comp.name]"
        v-bind="errorA11yAttrs"
        @update="emit('update', $event)"
    />

    <!-- Straight passthroughs to an existing Kinetix control. -->
    <component
        :is="delegateComponent"
        v-else-if="delegate"
        :value="values[comp.name]"
        v-bind="{ ...delegate.props(baseComp, values), ...errorA11yAttrs }"
        @update:value="emit('update', $event)"
    />

    <!-- A stored value exists but isn't sent: blank keeps it. -->
    <p
        v-if="withheld && (dedicated || delegate)"
        :id="`${comp.name}-withheld`"
        class="text-xs mt-1 text-muted-foreground"
    >
        {{ t('kinetix.form_withheld_hint') }}
    </p>
</template>
