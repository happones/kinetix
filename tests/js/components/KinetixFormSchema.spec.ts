import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { createI18n } from 'vue-i18n';
import KinetixFormSchema from '@/components/KinetixFormSchema.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    messages: { en: { kinetix: { add_item: 'Add item', not_set: 'Not set' } } },
});

const mountSchema = (schema: any[], values: Record<string, any> = {}) =>
    mount(KinetixFormSchema, {
        props: { schema, values, errors: {} },
        global: { plugins: [i18n] },
    });

describe('KinetixFormSchema', () => {
    it('renders a tokenized text input', () => {
        const wrapper = mountSchema(
            [
                {
                    type: 'text-input',
                    name: 'title',
                    label: 'Title',
                    columnSpan: 'full',
                    inputType: 'text',
                    isDisabled: false,
                },
            ],
            { title: 'hi' },
        );

        expect(wrapper.find('input[type="text"]').exists()).toBe(true);
    });

    it('renders a Reka switch for toggle fields and emits on change', async () => {
        const wrapper = mountSchema(
            [
                {
                    type: 'toggle',
                    name: 'active',
                    label: 'Active',
                    columnSpan: 'full',
                    isDisabled: false,
                },
            ],
            { active: false },
        );

        const toggle = wrapper.get('[role="switch"]');
        await toggle.trigger('click');

        expect(wrapper.emitted('update:value')?.[0]).toEqual(['active', true]);
    });

    it('renders a fieldset with its legend and nested field', () => {
        const wrapper = mountSchema([
            {
                type: 'fieldset',
                heading: 'Address',
                columnSpan: 'full',
                columns: 12,
                schema: [
                    {
                        type: 'text-input',
                        name: 'city',
                        label: 'City',
                        columnSpan: 6,
                    },
                ],
            },
        ]);

        expect(wrapper.find('fieldset').exists()).toBe(true);
        expect(wrapper.find('legend').text()).toBe('Address');
        expect(wrapper.find('#city').exists()).toBe(true);
    });

    it('renders a placeholder as read-only label + content (no input)', () => {
        const wrapper = mountSchema([
            {
                type: 'placeholder',
                label: 'Status',
                content: 'Active',
                columnSpan: 'full',
            },
        ]);

        expect(wrapper.text()).toContain('Status');
        expect(wrapper.text()).toContain('Active');
        expect(wrapper.find('input').exists()).toBe(false);
    });

    it('renders tabs with a trigger per tab and shows the first panel', () => {
        const wrapper = mountSchema([
            {
                type: 'tabs',
                columnSpan: 'full',
                schema: [
                    {
                        type: 'tab',
                        heading: 'Profile',
                        columns: 12,
                        schema: [
                            { type: 'text-input', name: 'name', label: 'Name' },
                        ],
                    },
                    {
                        type: 'tab',
                        heading: 'Security',
                        columns: 12,
                        schema: [
                            {
                                type: 'text-input',
                                name: 'pw',
                                label: 'Password',
                            },
                        ],
                    },
                ],
            },
        ]);

        const triggers = wrapper.findAll('[role="tab"]');
        expect(triggers).toHaveLength(2);
        expect(triggers[0].text()).toContain('Profile');
        // First tab's field is rendered in the active panel.
        expect(wrapper.find('#name').exists()).toBe(true);
    });
});
