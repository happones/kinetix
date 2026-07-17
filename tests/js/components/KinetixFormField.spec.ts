import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { createI18n } from 'vue-i18n';
import KinetixFormField from '@/components/Form/KinetixFormField.vue';
import CheckboxField from '@/components/Form/fields/CheckboxField.vue';
import HiddenField from '@/components/Form/fields/HiddenField.vue';
import SelectField from '@/components/Form/fields/SelectField.vue';
import TextInputField from '@/components/Form/fields/TextInputField.vue';

const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: {} } });

const STUBS = {
    KinetixCombobox: true,
    KinetixSelect: true,
    KinetixRating: true,
    KinetixPhoneInput: true,
    KinetixCopyableInput: true,
};

const mountField = (comp: any, values: Record<string, any> = {}) =>
    mount(KinetixFormField, {
        props: { comp, values, errors: {} },
        global: { plugins: [i18n], stubs: STUBS },
    });

describe('KinetixFormField dispatcher', () => {
    it('resolves dedicated field components by type', () => {
        expect(
            mountField({ type: 'text-input', name: 't' })
                .findComponent(TextInputField)
                .exists(),
        ).toBe(true);
        expect(
            mountField({ type: 'select', name: 's' })
                .findComponent(SelectField)
                .exists(),
        ).toBe(true);
        expect(
            mountField({ type: 'checkbox', name: 'c' })
                .findComponent(CheckboxField)
                .exists(),
        ).toBe(true);
        expect(
            mountField({ type: 'hidden', name: 'h' })
                .findComponent(HiddenField)
                .exists(),
        ).toBe(true);
    });

    it('forwards a dedicated field value edit as update', async () => {
        const wrapper = mountField(
            { type: 'text-input', name: 'title' },
            { title: 'hi' },
        );

        await wrapper.find('input[type="text"]').setValue('there');

        expect(wrapper.emitted('update')?.at(-1)).toEqual(['there']);
    });

    it('renders a delegate control with its built props', () => {
        const wrapper = mountField({
            type: 'rating',
            name: 'stars',
            ratingConfig: { max: 5 },
            isDisabled: false,
        });

        const rating = wrapper.findComponent({ name: 'KinetixRating' });
        expect(rating.exists()).toBe(true);
    });

    it('renders nothing for an unknown field type', () => {
        const wrapper = mountField({ type: 'nope', name: 'x' });
        expect(wrapper.find('*').exists()).toBe(false);
    });
});
