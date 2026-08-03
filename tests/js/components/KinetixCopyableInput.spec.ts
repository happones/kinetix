import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

import KinetixCopyableInput from '@/components/KinetixCopyableInput.vue';

const writeText = vi.fn().mockResolvedValue(undefined);
Object.defineProperty(navigator, 'clipboard', {
    value: { writeText },
    configurable: true,
});

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    missing: (_l: string, key: string) => key,
    messages: {
        en: {
            kinetix: { copy: 'Copy', reveal: 'Reveal', hide: 'Hide' },
        },
    },
});

const mountIt = (props: Record<string, unknown>) =>
    mount(KinetixCopyableInput, { props, global: { plugins: [i18n] } });

beforeEach(() => writeText.mockClear());

describe('KinetixCopyableInput', () => {
    it('copies the value to the clipboard', async () => {
        const w = mountIt({ value: 'sk-123', copyable: true });
        await w.find('button[aria-label="Copy"]').trigger('click');
        expect(writeText).toHaveBeenCalledWith('sk-123');
    });

    it('masks the value until revealed', async () => {
        const w = mountIt({ value: 'secret', revealable: true });
        expect(w.find('input').attributes('type')).toBe('password');

        await w.find('button[aria-label="Reveal"]').trigger('click');
        expect(w.find('input').attributes('type')).toBe('text');
    });

    it('emits update:value on input', async () => {
        const w = mountIt({ value: '', copyable: true });
        const input = w.find('input');
        (input.element as HTMLInputElement).value = 'typed';
        await input.trigger('input');
        expect(w.emitted('update:value')?.[0]).toEqual(['typed']);
    });

    it('clears the "copied" reset timer on unmount', async () => {
        vi.useFakeTimers();
        const w = mountIt({ value: 'sk-123', copyable: true });
        await w.find('button[aria-label="Copy"]').trigger('click');
        await flushPromises();
        expect(vi.getTimerCount()).toBe(1);

        w.unmount();
        expect(vi.getTimerCount()).toBe(0);
        vi.useRealTimers();
    });

    it('renders no buttons when neither copyable nor revealable', () => {
        const w = mountIt({ value: 'x' });
        expect(w.findAll('button')).toHaveLength(0);
    });
});
