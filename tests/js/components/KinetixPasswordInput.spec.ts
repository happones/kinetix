import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { createI18n } from 'vue-i18n';
import KinetixPasswordInput from '@/components/KinetixPasswordInput.vue';
import { fieldRequirements } from '@/composables/kinetixFieldConstraints';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    missing: (_l: string, key: string) => key.replace('kinetix.', ''),
    messages: { en: {} },
});

const t = (key: string) => key.replace('kinetix.', '');
const requirements = fieldRequirements(
    {
        kind: 'string',
        password: {
            min: 10,
            letters: true,
            mixedCase: true,
            numbers: true,
            symbols: false,
            uncompromised: true,
        },
    },
    t,
    (f) => f,
);

const mountPassword = (props: Record<string, unknown>) =>
    mount(KinetixPasswordInput, {
        props: { id: 'password', requirements, ...props },
        attrs: { 'aria-describedby': 'password-error', 'aria-invalid': 'true' },
        global: { plugins: [i18n] },
    });

describe('KinetixPasswordInput', () => {
    it('ticks off the policy as the user types', () => {
        const wrapper = mountPassword({ value: 'Secretpass' });
        const items = wrapper.findAll('li');

        expect(
            items.map((li) => li.classes().includes('text-success')),
        ).toEqual([
            true, // 10 characters
            true, // upper and lower
            false, // a number
            false, // known breach: the server's to say
        ]);
        expect(items[2].text()).toContain('rule_not_met');
        expect(items[3].text()).not.toContain('rule_not_met');
    });

    it('shows the strength once something is typed', () => {
        expect(mountPassword({ value: '' }).text()).not.toContain(
            'password_strength',
        );
        expect(
            mountPassword({ value: 'Correct-Horse-9-Battery' }).text(),
        ).toContain('password_strength');
    });

    it('reveals and hides the value', async () => {
        const wrapper = mountPassword({ value: 'x' });

        expect(wrapper.get('input').attributes('type')).toBe('password');

        await wrapper.get('button').trigger('click');

        expect(wrapper.get('input').attributes('type')).toBe('text');
        expect(wrapper.get('button').attributes('aria-pressed')).toBe('true');
    });

    it('keeps the field wiring on the input and adds the checklist to it', () => {
        const input = mountPassword({ value: '' }).get('input');

        expect(input.attributes('aria-invalid')).toBe('true');
        expect(input.attributes('aria-describedby')).toBe(
            'password-error password-policy',
        );
        expect(input.attributes('autocomplete')).toBe('new-password');
    });

    it('is a plain password field without a policy', () => {
        const wrapper = mountPassword({ value: 'x', requirements: [] });

        expect(wrapper.find('ul').exists()).toBe(false);
        expect(wrapper.get('input').attributes('aria-describedby')).toBe(
            'password-error',
        );
    });
});
