import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));

import KinetixGenerator from '@/components/KinetixGenerator.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: {
        en: {
            kinetix: {
                generate: 'Generate',
                show: 'Show',
                hide: 'Hide',
                copy: 'Copy',
            },
        },
    },
});

const mountGen = (props: Record<string, unknown>) =>
    mount(KinetixGenerator, { props, global: { plugins: [i18n] } });

describe('KinetixGenerator', () => {
    it('mode 1: renders its own input and emits a generated value', async () => {
        const wrapper = mountGen({
            config: { kind: 'pin', length: 4, pinMode: 'numeric' },
        });

        expect(wrapper.find('input').exists()).toBe(true);

        await wrapper.find('button[aria-label="Generate"]').trigger('click');

        const emitted = wrapper.emitted('update:value');
        expect(emitted).toBeTruthy();
        expect(String(emitted!.at(-1)![0])).toMatch(/^[0-9]{4}$/);
    });

    it('mode 2: no input, writes the value to a target callback', async () => {
        const received: string[] = [];
        const wrapper = mountGen({
            input: false,
            target: (v: string) => received.push(v),
            config: { kind: 'password', length: 12 },
        });

        // Button-only — no built-in input in target mode.
        expect(wrapper.find('input').exists()).toBe(false);

        await wrapper.find('button[aria-label="Generate"]').trigger('click');

        expect(received).toHaveLength(1);
        expect(received[0]).toHaveLength(12);
        // Still emits too, so it can double as v-model if wanted.
        expect(wrapper.emitted('update:value')).toBeTruthy();
    });

    it('masks the value until revealed when not revealable', async () => {
        const wrapper = mountGen({
            value: 'secret',
            config: { kind: 'password', revealable: false },
        });

        expect(wrapper.find('input').attributes('type')).toBe('password');

        await wrapper.find('button[aria-label="Show"]').trigger('click');
        expect(wrapper.find('input').attributes('type')).toBe('text');
    });

    it('renders a preset picker and regenerates on change', async () => {
        const wrapper = mountGen({ presets: ['uuid', 'otp'] });

        const select = wrapper.find('select');
        expect(select.exists()).toBe(true);
        expect(select.findAll('option')).toHaveLength(2);

        // Switch to the OTP preset — it regenerates and emits a 6-digit code.
        await select.setValue('otp');

        const emitted = wrapper.emitted('update:value');
        expect(emitted).toBeTruthy();
        expect(String(emitted!.at(-1)![0])).toMatch(/^\d{6}$/);
    });

    it('has no preset picker by default', () => {
        const wrapper = mountGen({ config: { kind: 'password' } });
        expect(wrapper.find('select').exists()).toBe(false);
    });

    // Documented as "resolves live from sibling values (like SlugInput)", but
    // the handle only appeared on a click.
    it('follows its pattern live until the user types their own', async () => {
        const config = { kind: 'username', pattern: '{first}.{last}' };
        const wrapper = mountGen({ config, values: {} });

        // Nothing to resolve yet: nothing filled in.
        expect(wrapper.emitted('update:value')).toBeUndefined();

        await wrapper.setProps({ values: { first: 'Ada', last: 'Lovelace' } });
        expect(wrapper.emitted('update:value')!.at(-1)).toEqual([
            'ada.lovelace',
        ]);

        // The parent applies it; the siblings change again: it follows.
        await wrapper.setProps({
            value: 'ada.lovelace',
            values: { first: 'Ada', last: 'Byron' },
        });
        expect(wrapper.emitted('update:value')!.at(-1)).toEqual(['ada.byron']);

        // The user typed their own: it stops following.
        await wrapper.setProps({ value: 'countess' });
        await wrapper.setProps({ values: { first: 'Grace', last: 'Hopper' } });
        expect(wrapper.emitted('update:value')!.at(-1)).toEqual(['ada.byron']);
    });

    it('puts the id and the error wiring on its input', () => {
        const wrapper = mount(KinetixGenerator, {
            props: { id: 'username', config: { kind: 'username' } },
            attrs: {
                class: 'mt-2',
                'aria-invalid': 'true',
                'aria-describedby': 'username-error',
            },
            global: { plugins: [i18n] },
        });

        const input = wrapper.find('input');
        expect(input.attributes('id')).toBe('username');
        expect(input.attributes('aria-invalid')).toBe('true');
        expect(input.attributes('aria-describedby')).toBe('username-error');
        expect(wrapper.classes()).toContain('mt-2');
        expect(wrapper.attributes('aria-invalid')).toBeUndefined();
    });
});
