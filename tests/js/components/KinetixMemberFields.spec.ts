import { enableAutoUnmount, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import KinetixFormSchema from '@/components/KinetixFormSchema.vue';
import KinetixMemberFields from '@/components/Members/KinetixMemberFields.vue';
import type { KinetixMemberFieldsForm } from '@/types/kinetix';
import { i18n } from './i18n';

enableAutoUnmount(afterEach);

const FORM: KinetixMemberFieldsForm = {
    schema: [
        {
            type: 'text-input',
            name: 'badge',
            label: 'Badge',
            description: 'Printed on the card.',
            columnSpan: 1,
        },
    ],
    data: {},
    rules: {},
    operation: 'edit',
};

const mountFields = (props: Record<string, unknown> = {}) =>
    mount(KinetixMemberFields, {
        attachTo: document.body,
        props: { form: FORM, modelValue: {}, ...props },
        global: { plugins: [i18n] },
    });

const until = (check: () => unknown) =>
    vi.waitFor(() => expect(check()).toBeTruthy());

describe('KinetixMemberFields id scope', () => {
    it('gives two instances of the same field no id collision', async () => {
        // One app, as on a real page: the provisioner and the Edit dialog.
        const Two = defineComponent({
            setup: () => () =>
                h('div', [
                    h(KinetixMemberFields, {
                        form: FORM,
                        modelValue: {},
                        errors: { badge: 'Taken.' },
                    }),
                    h(KinetixMemberFields, {
                        form: FORM,
                        modelValue: {},
                        errors: { badge: 'Taken.' },
                    }),
                ]),
        });
        const root = mount(Two, {
            attachTo: document.body,
            global: { plugins: [i18n] },
        });

        await until(() => root.findAll('input').length === 2);

        const ids = [...document.querySelectorAll('[id]')].map((el) => el.id);
        expect(new Set(ids).size).toBe(ids.length);

        for (const wrapper of root.findAllComponents(KinetixMemberFields)) {
            const input = wrapper.get('input');
            const id = input.attributes('id');
            const label = wrapper.get('label');

            // Label, helper and error all follow the instance's prefix.
            expect(label.attributes('for')).toBe(id);
            expect(id).not.toBe('badge');
            expect(input.attributes('aria-describedby')).toBe(
                `${id}-help ${id}-error`,
            );
            expect(wrapper.find(`[id="${id}-error"]`).exists()).toBe(true);
            expect(wrapper.find(`[id="${id}-help"]`).exists()).toBe(true);
        }
    });

    it('focuses a field by name through its scoped id', async () => {
        const wrapper = mountFields();

        await until(() => wrapper.find('input').exists());
        (wrapper.vm as unknown as { focus: (n: string) => void }).focus(
            'badge',
        );

        await until(
            () => document.activeElement === wrapper.get('input').element,
        );
    });
});

describe('form field ids without a scope', () => {
    it('stay exactly the field name', async () => {
        const wrapper = mount(KinetixFormSchema, {
            props: { schema: FORM.schema, values: {}, errors: {} },
            global: { plugins: [i18n] },
        });

        await until(() => wrapper.find('input').exists());
        expect(wrapper.get('input').attributes('id')).toBe('badge');
        expect(wrapper.get('label').attributes('for')).toBe('badge');
    });
});
