import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { nextTick, reactive } from 'vue';
import { createI18n } from 'vue-i18n';
import { useKinetixFieldConditions } from '@/composables/useKinetixFieldConditions';

const page = reactive<{ props: { errors: Record<string, string> } }>({
    props: { errors: {} },
});
vi.mock('@inertiajs/vue3', () => ({ usePage: () => page }));

import KinetixForm from '@/components/KinetixForm.vue';

const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: {} } });

describe('useKinetixFieldConditions', () => {
    const { passes, resolve } = useKinetixFieldConditions();

    it('evaluates each operator', () => {
        expect(
            passes({ field: 't', operator: 'equals', value: 'a' }, { t: 'a' }),
        ).toBe(true);
        expect(
            passes({ field: 't', operator: 'equals', value: 'a' }, { t: 'b' }),
        ).toBe(false);
        expect(
            passes(
                { field: 't', operator: 'notEquals', value: 'a' },
                { t: 'b' },
            ),
        ).toBe(true);
        expect(
            passes(
                { field: 't', operator: 'in', value: ['a', 'b'] },
                { t: 'b' },
            ),
        ).toBe(true);
        expect(
            passes(
                { field: 't', operator: 'notIn', value: ['a', 'b'] },
                { t: 'c' },
            ),
        ).toBe(true);
        expect(passes({ field: 't', operator: 'truthy' }, { t: 1 })).toBe(true);
        expect(passes({ field: 't', operator: 'falsy' }, { t: 0 })).toBe(true);
        expect(passes({ field: 't', operator: 'filled' }, { t: 'x' })).toBe(
            true,
        );
        expect(passes({ field: 't', operator: 'blank' }, { t: '' })).toBe(true);
    });

    it('loosely compares (string/number)', () => {
        expect(
            passes({ field: 't', operator: 'equals', value: 1 }, { t: '1' }),
        ).toBe(true);
    });

    it('resolve returns visible/disabled/required flags', () => {
        const comp = {
            conditions: {
                visible: {
                    field: 'type',
                    operator: 'equals' as const,
                    value: 'company',
                },
                required: {
                    field: 'type',
                    operator: 'equals' as const,
                    value: 'company',
                },
            },
        };

        expect(resolve(comp, { type: 'person' })).toEqual({
            visible: false,
            disabled: false,
            required: false,
        });
        expect(resolve(comp, { type: 'company' })).toEqual({
            visible: true,
            disabled: false,
            required: true,
        });
    });

    it('a field without conditions is visible and unconstrained', () => {
        expect(resolve({}, {})).toEqual({
            visible: true,
            disabled: false,
            required: null,
        });
    });
});

describe('KinetixForm — conditional fields', () => {
    const form = {
        schema: [
            { type: 'text-input', name: 'type', label: 'Type' },
            {
                type: 'text-input',
                name: 'company_name',
                label: 'Company name',
                conditions: {
                    visible: {
                        field: 'type',
                        operator: 'equals',
                        value: 'company',
                    },
                },
            },
        ],
        data: { type: 'person', company_name: '' },
        rules: {},
        operation: 'create',
    };

    it('hides a field whose visibleWhen condition fails and reveals it when it holds', async () => {
        page.props.errors = {};

        const wrapper = mount(KinetixForm, {
            props: { form },
            global: { plugins: [i18n] },
        });

        // type = 'person' → company_name hidden.
        expect(wrapper.find('#company_name').exists()).toBe(false);

        // Flip type to 'company' → company_name revealed.
        await wrapper.setProps({
            form: { ...form, data: { type: 'company', company_name: '' } },
        });
        await nextTick();

        expect(wrapper.find('#company_name').exists()).toBe(true);
    });
});
