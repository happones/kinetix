import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { nextTick, reactive } from 'vue';
import { createI18n } from 'vue-i18n';

// A mutable page-props stand-in so tests can push server errors in.
const page = reactive<{ props: { errors: Record<string, string> } }>({
    props: { errors: {} },
});
vi.mock('@inertiajs/vue3', () => ({ usePage: () => page }));

import KinetixForm from '@/components/KinetixForm.vue';

const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: {} } });

const form = {
    schema: [{ type: 'text-input', name: 'email', label: 'Email' }],
    data: { email: '' },
    rules: {},
    operation: 'create',
};

const mountForm = () => {
    page.props.errors = {};

    return mount(KinetixForm, {
        props: { form },
        global: { plugins: [i18n] },
    });
};

describe('KinetixForm', () => {
    it('renders helper text and chains it with the error in aria-describedby', async () => {
        page.props.errors = {};

        const wrapper = mount(KinetixForm, {
            props: {
                form: {
                    schema: [
                        {
                            type: 'text-input',
                            name: 'email',
                            label: 'Email',
                            description: 'We never share it.',
                        },
                    ],
                    data: { email: '' },
                    rules: {},
                    operation: 'create',
                },
            },
            global: { plugins: [i18n] },
        });

        // Helper text renders with its addressable id and is referenced.
        const help = wrapper.get('#email-help');
        expect(help.text()).toBe('We never share it.');
        expect(wrapper.get('input').attributes('aria-describedby')).toBe(
            'email-help',
        );

        // With an error, describedby chains BOTH ids.
        page.props.errors = { email: 'Invalid email' };
        await wrapper.vm.$nextTick();

        expect(wrapper.get('input').attributes('aria-describedby')).toBe(
            'email-help email-error',
        );
        expect(wrapper.get('input').attributes('aria-invalid')).toBe('true');

        wrapper.unmount();
    });

    it('renders a server validation error from Inertia page props', async () => {
        const wrapper = mountForm();
        expect(wrapper.text()).not.toContain('The email is required.');

        page.props.errors = { email: 'The email is required.' };
        await nextTick();

        expect(wrapper.text()).toContain('The email is required.');
    });

    it('dismisses a stale server error once the field is edited', async () => {
        const wrapper = mountForm();
        page.props.errors = { email: 'The email is required.' };
        await nextTick();
        expect(wrapper.text()).toContain('The email is required.');

        await wrapper.get('input').setValue('me@example.com');
        await nextTick();

        expect(wrapper.text()).not.toContain('The email is required.');
    });

    it('emits submit with the current values', async () => {
        const wrapper = mountForm();
        await wrapper.get('input').setValue('me@example.com');
        await wrapper.get('form').trigger('submit');

        expect(wrapper.emitted('submit')?.[0]).toEqual([
            { email: 'me@example.com' },
        ]);
    });

    // A failed validation round-trip reruns the controller, which re-serializes
    // the form from the ORIGINAL record — the incoming props must not overwrite
    // what the user just submitted.
    it('keeps the submitted values after a failed validation round-trip (edit: cleared field must NOT refill from the record)', async () => {
        page.props.errors = {};

        const wrapper = mount(KinetixForm, {
            props: {
                form: { ...form, data: { email: 'original@example.com' } },
            },
            global: { plugins: [i18n] },
        });

        // The user clears the required field and submits.
        await wrapper.get('input').setValue('');
        await wrapper.get('form').trigger('submit');

        // Error-back: errors arrive AND the controller reran, shipping the
        // record's original data again in the same render.
        page.props.errors = { email: 'The email is required.' };
        await wrapper.setProps({
            form: { ...form, data: { email: 'original@example.com' } },
        });
        await nextTick();

        expect((wrapper.get('input').element as HTMLInputElement).value).toBe(
            '',
        );
        expect(wrapper.text()).toContain('The email is required.');
    });

    it('keeps the typed values after a failed create submit (blank blueprint must NOT wipe the input)', async () => {
        page.props.errors = {};

        const wrapper = mount(KinetixForm, {
            props: {
                form: {
                    ...form,
                    schema: [
                        { type: 'text-input', name: 'email', label: 'Email' },
                        { type: 'text-input', name: 'name', label: 'Name' },
                    ],
                    data: { email: '', name: '' },
                },
            },
            global: { plugins: [i18n] },
        });

        const inputs = wrapper.findAll('input');
        await inputs[1].setValue('Ada Lovelace');
        await wrapper.get('form').trigger('submit');

        // Error-back re-ships the blank create blueprint.
        page.props.errors = { email: 'The email is required.' };
        await wrapper.setProps({
            form: {
                ...form,
                schema: [
                    { type: 'text-input', name: 'email', label: 'Email' },
                    { type: 'text-input', name: 'name', label: 'Name' },
                ],
                data: { email: '', name: '' },
            },
        });
        await nextTick();

        expect(
            (wrapper.findAll('input')[1].element as HTMLInputElement).value,
        ).toBe('Ada Lovelace');
    });

    it('re-syncs from props once an error-free render arrives (successful save)', async () => {
        page.props.errors = {};

        const wrapper = mount(KinetixForm, {
            props: { form: { ...form, data: { email: 'old@example.com' } } },
            global: { plugins: [i18n] },
        });

        await wrapper.get('input').setValue('new@example.com');

        // Successful save: fresh props, empty error bag → sync.
        page.props.errors = {};
        await wrapper.setProps({
            form: { ...form, data: { email: 'saved@example.com' } },
        });
        await nextTick();

        expect((wrapper.get('input').element as HTMLInputElement).value).toBe(
            'saved@example.com',
        );
    });
});
