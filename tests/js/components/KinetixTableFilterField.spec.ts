import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { createI18n } from 'vue-i18n';
import KinetixTableFilterField from '@/components/Table/KinetixTableFilterField.vue';
import FilterAddressField from '@/components/Table/filters/FilterAddressField.vue';
import FilterNumberRangeField from '@/components/Table/filters/FilterNumberRangeField.vue';
import FilterSelectField from '@/components/Table/filters/FilterSelectField.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: {
        en: {
            kinetix: {
                all: 'All',
                min: 'Min',
                max: 'Max',
                address_search: 'Search address',
                enable_filter: 'Enable',
            },
        },
    },
});

const filter = (extra: Record<string, unknown>) =>
    ({ name: 'f', label: 'F', default: null, ...extra }) as any;

const mountField = (f: any, value: unknown = null) =>
    mount(KinetixTableFilterField, {
        props: { filter: f, value },
        global: {
            plugins: [i18n],
            stubs: {
                KinetixSelect: true,
                KinetixCombobox: true,
                KinetixCheckboxList: true,
                KinetixDatePicker: true,
                KinetixDateTimePicker: true,
                KinetixRangeCalendar: true,
                KinetixMonthPicker: true,
                KinetixYearPicker: true,
                KinetixWeekPicker: true,
            },
        },
    });

describe('KinetixTableFilterField dispatcher', () => {
    it('resolves the select field for select and ternary types', () => {
        expect(
            mountField(filter({ type: 'select' }))
                .findComponent(FilterSelectField)
                .exists(),
        ).toBe(true);
        expect(
            mountField(filter({ type: 'ternary' }))
                .findComponent(FilterSelectField)
                .exists(),
        ).toBe(true);
    });

    it('resolves the address field and forwards typed input as an update', async () => {
        const wrapper = mountField(filter({ type: 'address' }), '');
        expect(wrapper.findComponent(FilterAddressField).exists()).toBe(true);

        const input = wrapper.find('input[type="text"]');
        await input.setValue('main st');

        expect(wrapper.emitted('update')?.at(-1)).toEqual(['main st']);
    });

    it('merges a single bound for the number-range field', async () => {
        const wrapper = mountField(filter({ type: 'number-range' }), {
            min: '5',
        });
        expect(wrapper.findComponent(FilterNumberRangeField).exists()).toBe(
            true,
        );

        const maxInput = wrapper.findAll('input[type="number"]')[1];
        await maxInput.setValue('20');

        // The existing min bound is preserved alongside the new max.
        expect(wrapper.emitted('update')?.at(-1)).toEqual([
            { min: '5', max: '20' },
        ]);
    });

    it('renders nothing for an unknown filter type', () => {
        const wrapper = mountField(filter({ type: 'nope' }));
        expect(wrapper.find('*').exists()).toBe(false);
    });
});
