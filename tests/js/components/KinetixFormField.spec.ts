import { mount } from '@vue/test-utils';
import { beforeAll, describe, expect, it } from 'vitest';
import { createI18n } from 'vue-i18n';
import KinetixFormField from '@/components/Form/KinetixFormField.vue';
import CheckboxField from '@/components/Form/fields/CheckboxField.vue';
import HiddenField from '@/components/Form/fields/HiddenField.vue';
import SelectField from '@/components/Form/fields/SelectField.vue';
import TextInputField from '@/components/Form/fields/TextInputField.vue';
import { i18n as kinetixI18n } from './i18n';
import { preloadAllKinetixLazy } from '../support/lazyComponents';

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

// Lazy field types, filters and modal bodies, loaded so mounts stay synchronous.
beforeAll(preloadAllKinetixLazy);

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

describe('KinetixFormField withheld values', () => {
    const mountWithheld = (comp: any) =>
        mount(KinetixFormField, {
            props: { comp, values: {}, errors: {} },
            global: { plugins: [kinetixI18n], stubs: STUBS },
        });

    it('says a value is saved and chains the hint into describedby', () => {
        const wrapper = mountWithheld({
            type: 'text-input',
            name: 'api_key',
            isWithheld: true,
            description: 'From the provider dashboard',
        });

        const input = wrapper.find('input');
        expect(input.attributes('placeholder')).toBe(
            'Saved — leave blank to keep',
        );

        const hint = wrapper.find('#api_key-withheld');
        expect(hint.exists()).toBe(true);
        expect(hint.text()).toBe(
            'A value is saved. Leave this blank to keep it.',
        );
        expect(input.attributes('aria-describedby')).toBe(
            'api_key-help api_key-withheld',
        );
    });

    it("keeps the field's own placeholder", () => {
        const wrapper = mountWithheld({
            type: 'text-input',
            name: 'api_key',
            isWithheld: true,
            placeholder: 'sk_live_…',
        });

        expect(wrapper.find('input').attributes('placeholder')).toBe(
            'sk_live_…',
        );
        expect(wrapper.find('#api_key-withheld').exists()).toBe(true);
    });

    it('passes the placeholder to delegate controls too', () => {
        const wrapper = mountWithheld({
            type: 'json-input',
            name: 'payload',
            isWithheld: true,
        });

        expect(
            wrapper
                .findComponent({ name: 'KinetixJsonInput' })
                .props('placeholder'),
        ).toBe('Saved — leave blank to keep');
    });

    it('renders nothing extra when the value is not withheld', () => {
        const wrapper = mountWithheld({
            type: 'text-input',
            name: 'api_key',
            isWithheld: false,
        });

        const input = wrapper.find('input');
        expect(input.attributes('placeholder')).toBeUndefined();
        expect(input.attributes('aria-describedby')).toBeUndefined();
        expect(wrapper.find('#api_key-withheld').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('A value is saved');
    });
});
