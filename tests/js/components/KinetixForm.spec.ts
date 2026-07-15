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
});
