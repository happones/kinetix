<script setup lang="ts">
import { computed } from 'vue';
import type { Component } from 'vue';
import KinetixAddressPicker from '../KinetixAddressPicker.vue';
import KinetixCheckboxList from '../KinetixCheckboxList.vue';
import KinetixDateRangePicker from '../KinetixDateRangePicker.vue';
import KinetixFileUpload from '../KinetixFileUpload.vue';
import KinetixKeyValue from '../KinetixKeyValue.vue';
import KinetixMediaLibrary from '../KinetixMediaLibrary.vue';
import KinetixNumberField from '../KinetixNumberField.vue';
import KinetixPhoneInput from '../KinetixPhoneInput.vue';
import KinetixPinInput from '../KinetixPinInput.vue';
import KinetixRadioGroup from '../KinetixRadioGroup.vue';
import KinetixRating from '../KinetixRating.vue';
import KinetixRichEditor from '../KinetixRichEditor.vue';
import KinetixSignaturePad from '../KinetixSignaturePad.vue';
import KinetixSlider from '../KinetixSlider.vue';
import KinetixSlugInput from '../KinetixSlugInput.vue';
import KinetixTagsInput from '../KinetixTagsInput.vue';
import CheckboxField from './fields/CheckboxField.vue';
import ColorPickerField from './fields/ColorPickerField.vue';
import DatePickerField from './fields/DatePickerField.vue';
import DateTimePickerField from './fields/DateTimePickerField.vue';
import HiddenField from './fields/HiddenField.vue';
import PeriodPickerField from './fields/PeriodPickerField.vue';
import SelectField from './fields/SelectField.vue';
import TableRepeaterField from './fields/TableRepeaterField.vue';
import TextareaField from './fields/TextareaField.vue';
import TextInputField from './fields/TextInputField.vue';
import TimePickerField from './fields/TimePickerField.vue';
import ToggleField from './fields/ToggleField.vue';

const props = defineProps<{
    comp: any;
    values: Record<string, any>;
    errors: Record<string, string>;
}>();

const emit = defineEmits<{ (e: 'update', value: any): void }>();

/**
 * Field-type → component map. Resolving by lookup (O(1)) rather than a ~35-way
 * `v-if`/`v-else-if` chain keeps the render fn flat, avoids re-evaluating every
 * branch on each update, and makes adding a field type a one-line entry. Each
 * dedicated field shares the `{ comp, value } -> update` contract.
 *
 * Layout nodes and the recursive inline `repeater` are handled by
 * `KinetixFormSchema` directly (the repeater renders the schema recursively).
 */
const DEDICATED_FIELDS: Record<string, Component> = {
    'text-input': TextInputField,
    select: SelectField,
    checkbox: CheckboxField,
    toggle: ToggleField,
    textarea: TextareaField,
    'date-picker': DatePickerField,
    'datetime-picker': DateTimePickerField,
    'time-picker': TimePickerField,
    'month-picker': PeriodPickerField,
    'year-picker': PeriodPickerField,
    'week-picker': PeriodPickerField,
    'color-picker': ColorPickerField,
    hidden: HiddenField,
};

/**
 * Straight passthroughs to an existing Kinetix control: a `:value` +
 * `@update:value` component plus a per-type prop builder. Kept as map entries
 * rather than one wrapper file each.
 */
type Delegate = {
    component: Component;
    props: (comp: any, values: Record<string, any>) => Record<string, unknown>;
};

const DELEGATE_FIELDS: Record<string, Delegate> = {
    'phone-input': {
        component: KinetixPhoneInput,
        props: (c) => ({
            config: c.phoneConfig,
            disabled: c.isDisabled,
            placeholder: c.placeholder,
        }),
    },
    'slug-input': {
        component: KinetixSlugInput,
        props: (c, values) => ({
            source: c.slugConfig?.from ? values[c.slugConfig.from] : null,
            config: c.slugConfig,
            disabled: c.isDisabled,
            placeholder: c.placeholder,
        }),
    },
    'signature-pad': {
        component: KinetixSignaturePad,
        props: (c) => ({ config: c.signatureConfig, disabled: c.isDisabled }),
    },
    slider: {
        component: KinetixSlider,
        props: (c) => ({ config: c.numberConfig, disabled: c.isDisabled }),
    },
    rating: {
        component: KinetixRating,
        props: (c) => ({ config: c.ratingConfig, disabled: c.isDisabled }),
    },
    'pin-input': {
        component: KinetixPinInput,
        props: (c) => ({ config: c.pinConfig, disabled: c.isDisabled }),
    },
    'number-field': {
        component: KinetixNumberField,
        props: (c) => ({
            config: c.numberConfig,
            disabled: c.isDisabled,
            placeholder: c.placeholder,
        }),
    },
    'rich-editor': {
        component: KinetixRichEditor,
        props: (c) => ({
            editor: c.editor,
            disabled: c.isDisabled,
            placeholder: c.placeholder,
        }),
    },
    'address-picker': {
        component: KinetixAddressPicker,
        props: (c) => ({
            fields: c.addressFields,
            countries: c.options,
            disabled: c.isDisabled,
        }),
    },
    'date-range-picker': {
        component: KinetixDateRangePicker,
        props: (c) => ({
            native: !c.useCalendar,
            disabled: c.isDisabled,
            placeholder: c.placeholder,
            locale: c.dateLocale,
            weekdayFormat: c.weekdayFormat,
            numberOfMonths: c.numberOfMonths,
            fixedWeeks: c.fixedWeeks,
            minValue: c.minValue,
            maxValue: c.maxValue,
        }),
    },
    radio: {
        component: KinetixRadioGroup,
        props: (c) => ({
            options: c.options,
            inline: c.isInline,
            disabled: c.isDisabled,
        }),
    },
    'checkbox-list': {
        component: KinetixCheckboxList,
        props: (c) => ({
            options: c.options,
            inline: c.isInline,
            disabled: c.isDisabled,
            searchable: c.isSearchable,
            searchToken: c.searchToken,
        }),
    },
    'tags-input': {
        component: KinetixTagsInput,
        props: (c) => ({ disabled: c.isDisabled, placeholder: c.placeholder }),
    },
    'key-value': {
        component: KinetixKeyValue,
        props: (c) => ({ disabled: c.isDisabled }),
    },
    'file-upload': {
        component: KinetixFileUpload,
        props: (c) => ({
            uploadToken: c.uploadToken,
            isMultiple: c.isMultiple,
            acceptedFileTypes: c.acceptedFileTypes,
            maxSize: c.maxSize,
            isImage: c.isImage,
            maxFiles: c.maxFiles,
            disabled: c.isDisabled,
        }),
    },
    'media-library': {
        component: KinetixMediaLibrary,
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

const dedicated = computed<Component | null>(
    () => DEDICATED_FIELDS[props.comp.type] ?? null,
);
const delegate = computed<Delegate | null>(
    () => DELEGATE_FIELDS[props.comp.type] ?? null,
);
</script>

<template>
    <!-- Repeater with nested table columns (self-contained). -->
    <TableRepeaterField
        v-if="comp.type === 'table-repeater'"
        :comp="comp"
        :value="values[comp.name]"
        :errors="errors"
        @update="emit('update', $event)"
    />

    <!-- Dedicated field components (custom markup / internal branching). -->
    <component
        :is="dedicated"
        v-else-if="dedicated"
        :comp="comp"
        :value="values[comp.name]"
        @update="emit('update', $event)"
    />

    <!-- Straight passthroughs to an existing Kinetix control. -->
    <component
        :is="delegate.component"
        v-else-if="delegate"
        :value="values[comp.name]"
        v-bind="delegate.props(comp, values)"
        @update:value="emit('update', $event)"
    />
</template>
