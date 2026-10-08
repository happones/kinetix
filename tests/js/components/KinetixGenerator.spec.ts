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
});
