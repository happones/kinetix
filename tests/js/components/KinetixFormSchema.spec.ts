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

    it('links an errored field to its announced error text', () => {
        const wrapper = mount(KinetixFormSchema, {
            props: {
                schema: [
                    {
                        type: 'text-input',
                        name: 'title',
                        label: 'Title',
                        columnSpan: 'full',
                        inputType: 'text',
                        isDisabled: false,
                    },
                ],
                values: { title: '' },
                errors: { title: 'The title is required.' },
            },
            global: { plugins: [i18n] },
        });

        const input = wrapper.get('input[type="text"]');
        expect(input.attributes('aria-invalid')).toBe('true');
        expect(input.attributes('aria-describedby')).toBe('title-error');

        const error = wrapper.get('#title-error');
        expect(error.attributes('role')).toBe('alert');
        expect(error.text()).toBe('The title is required.');
    });

    it('emits responsive grid vars for breakpoint column maps', () => {
        const wrapper = mountSchema(
            [
                {
                    type: 'grid',
                    columns: { default: 1, sm: 2, xl: 3 },
                    columnSpan: 'full',
                    schema: [
                        {
                            type: 'text-input',
                            name: 'a',
                            label: 'A',
                            columnSpan: 2,
                        },
                    ],
                },
            ],
            { a: '' },
        );

        const grid = wrapper.get('.kinetix-grid');
        expect(grid.attributes('style')).toContain('--kx-cols-base: 1');
        expect(grid.attributes('style')).toContain('--kx-cols-sm: 2');
        expect(grid.attributes('style')).toContain('--kx-cols-xl: 3');

        // The field's span clamps to the columns available per breakpoint.
        const field = wrapper.get('.kinetix-grid .kinetix-col');
        expect(field.attributes('style')).toContain(
            '--kx-span-base: span 1 / span 1',
        );
        expect(field.attributes('style')).toContain(
            '--kx-span-sm: span 2 / span 2',
        );
    });

    it('int columns collapse below lg (Filament parity)', () => {
        const wrapper = mountSchema(
            [
                {
                    type: 'grid',
                    columns: 2,
                    columnSpan: 'full',
                    schema: [{ type: 'text-input', name: 'a', label: 'A' }],
                },
            ],
            { a: '' },
        );

        const grid = wrapper.get('.kinetix-grid');
        expect(grid.attributes('style')).toContain('--kx-cols-base: 1');
        expect(grid.attributes('style')).toContain('--kx-cols-md: 1');
        expect(grid.attributes('style')).toContain('--kx-cols-lg: 2');
    });

    it('leaves clean fields without error aria wiring', () => {
        const wrapper = mount(KinetixFormSchema, {
            props: {
                schema: [
                    {
                        type: 'text-input',
                        name: 'title',
                        label: 'Title',
                        columnSpan: 'full',
                        inputType: 'text',
                        isDisabled: false,
                    },
                ],
                values: { title: 'ok' },
                errors: {},
            },
            global: { plugins: [i18n] },
        });

        const input = wrapper.get('input[type="text"]');
        expect(input.attributes('aria-invalid')).toBeUndefined();
        expect(input.attributes('aria-describedby')).toBeUndefined();
    });
});

describe('KinetixFormSchema flat mode (forms hosted in modals)', () => {
    const sectionSchema = [
        {
            type: 'section',
            heading: 'Details',
            description: 'Basic information.',
            columnSpan: 'full',
            columns: 12,
            schema: [
                {
                    type: 'text-input',
                    name: 'title',
                    label: 'Title',
                    columnSpan: 'full',
                },
            ],
        },
    ];

    const mountFlat = (schema: any[], flat: boolean) =>
        mount(KinetixFormSchema, {
            props: { schema, values: {}, errors: {}, flat },
            global: { plugins: [i18n] },
        });

    it('renders Sections as cards by default (pages own no surface)', () => {
        const wrapper = mountFlat(sectionSchema, false);

        const section = wrapper.get('.kinetix-col');
        expect(section.classes()).toContain('rounded-xl');
        expect(section.classes()).toContain('shadow-sm');
        expect(section.classes()).toContain('bg-background');
    });

    it('flat drops the Section card chrome — the modal is the surface', () => {
        const wrapper = mountFlat(sectionSchema, true);

        const section = wrapper.get('.kinetix-col');
        expect(section.classes()).not.toContain('rounded-xl');
        expect(section.classes()).not.toContain('shadow-sm');
        expect(section.classes()).not.toContain('bg-background');

        // Heading, description and fields still render.
        expect(wrapper.get('h3').text()).toBe('Details');
        expect(wrapper.text()).toContain('Basic information.');
        expect(wrapper.find('#title').exists()).toBe(true);
    });

    it('flat propagates into nested layouts (Grid > Section)', () => {
        const wrapper = mountFlat(
            [
                {
                    type: 'grid',
                    columnSpan: 'full',
                    columns: 12,
                    schema: sectionSchema,
                },
            ],
            true,
        );

        expect(wrapper.find('.rounded-xl').exists()).toBe(false);
        expect(wrapper.get('h3').text()).toBe('Details');
    });

    describe("what a field's rules ask for", () => {
        const ruleI18n = createI18n({
            legacy: false,
            locale: 'en',
            missingWarn: false,
            fallbackWarn: false,
            missing: (_locale: string, key: string) =>
                key.replace('kinetix.', ''),
            messages: { en: {} },
        });

        const field = (name: string, constraints: any, extra: any = {}) => ({
            type: 'text-input',
            name,
            label: name,
            columnSpan: 'full',
            inputType: 'text',
            isDisabled: false,
            constraints,
            ...extra,
        });

        const mountRules = (schema: any[], values: Record<string, any>) =>
            mount(KinetixFormSchema, {
                props: { schema, values, errors: {} },
                global: { plugins: [ruleI18n] },
            });

        it('shows the hints and a counter, chained to the input', () => {
            const wrapper = mountRules(
                [
                    field('code', {
                        kind: 'string',
                        length: { max: 8 },
                        charset: 'alpha_dash',
                    }),
                ],
                { code: 'abc' },
            );

            expect(wrapper.get('#code-rules').text()).toBe(
                'rule_charset_alpha_dash',
            );
            expect(wrapper.text()).toContain('3/8');
            expect(wrapper.get('input').attributes('aria-describedby')).toBe(
                'code-rules',
            );
            expect(wrapper.get('input').attributes('spellcheck')).toBe('false');
        });

        // Validate on blur, not on the first keystroke; then live as it's fixed.
        it('says what is wrong only once the field is left', async () => {
            const wrapper = mountRules(
                [field('code', { kind: 'string', charset: 'alpha_dash' })],
                { code: 'my code' },
            );

            expect(wrapper.find('[role="alert"]').exists()).toBe(false);

            await wrapper.get('input').trigger('focusout');

            expect(wrapper.get('[role="alert"]').text()).toBe(
                'rule_charset_alpha_dash',
            );
            expect(wrapper.get('input').attributes('aria-invalid')).toBe(
                'true',
            );
            // The hint isn't repeated under the error.
            expect(wrapper.find('#code-rules').exists()).toBe(false);

            await wrapper.setProps({ values: { code: 'my-code' } });

            expect(wrapper.find('[role="alert"]').exists()).toBe(false);
        });

        it('lets a server error win over its own check', async () => {
            const wrapper = mountRules(
                [field('code', { kind: 'string', charset: 'alpha_dash' })],
                { code: 'my code' },
            );

            await wrapper.setProps({ errors: { code: 'Taken.' } });
            await wrapper.get('input').trigger('focusout');

            expect(wrapper.get('[role="alert"]').text()).toBe('Taken.');
        });

        it('confirms a matching confirmation', () => {
            const wrapper = mountRules(
                [
                    field('password', null),
                    field('password_confirmation', {
                        kind: 'string',
                        compare: [{ op: 'same', field: 'password' }],
                    }),
                ],
                { password: 'secret-1', password_confirmation: 'secret-1' },
            );

            expect(wrapper.text()).toContain('rule_matches');
        });

        it('applies the case the rules ask for as the user types', async () => {
            const wrapper = mountRules(
                [field('sku', { kind: 'string', case: 'upper' })],
                { sku: '' },
            );

            await wrapper.get('input').setValue('ab-12');

            expect(wrapper.emitted('update:value')?.at(-1)).toEqual([
                'sku',
                'AB-12',
            ]);
        });
    });
});
