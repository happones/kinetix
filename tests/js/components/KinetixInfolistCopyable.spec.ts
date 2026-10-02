import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { i18n } from './i18n';
import KinetixInfolistEntries from '@/components/KinetixInfolistEntries.vue';

const writeText = vi.fn();

const mountEntry = (entry: Record<string, unknown>) =>
    mount(KinetixInfolistEntries, {
        props: {
            schema: [{ name: 'field', label: 'Field', ...entry }] as never,
        },
        global: { plugins: [i18n] },
    });

beforeEach(() => {
    writeText.mockReset().mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'clipboard', {
        value: { writeText },
        configurable: true,
    });
});

describe('KinetixInfolistEntries copyable entries', () => {
    it('makes a plain text value itself the copy trigger', async () => {
        const w = mountEntry({
            type: 'text',
            state: 'ada@acme.dev',
            isCopyable: true,
        });
        const trigger = w.get('button');

        expect(trigger.text()).toBe('ada@acme.dev');

        await trigger.trigger('click');
        await flushPromises();

        expect(writeText).toHaveBeenCalledWith('ada@acme.dev');
    });

    it('makes the badge pill the trigger', async () => {
        const w = mountEntry({
            type: 'text',
            state: 'Pro',
            isBadge: true,
            isCopyable: true,
        });

        expect(w.get('button').text()).toBe('Pro');

        await w.get('button').trigger('click');
        await flushPromises();

        expect(writeText).toHaveBeenCalledWith('Pro');
    });

    it('keeps a linked value a link with a labelled copy button beside it', async () => {
        const w = mountEntry({
            type: 'text',
            state: 'acme.dev',
            url: 'https://acme.dev',
            isCopyable: true,
        });

        expect(w.get('a').element.closest('button')).toBeNull();

        await w.get('button[aria-label="Copy"]').trigger('click');
        await flushPromises();

        expect(writeText).toHaveBeenCalledWith('acme.dev');
    });

    it('makes the swatch and hex code the trigger of a copyable color', async () => {
        const w = mountEntry({
            type: 'color',
            state: '#6366f1',
            isCopyable: true,
        });

        await w.get('button').trigger('click');
        await flushPromises();

        expect(writeText).toHaveBeenCalledWith('#6366f1');
    });

    it('renders no copy trigger when the entry is not copyable or empty', () => {
        for (const entry of [
            { type: 'text', state: 'x' },
            { type: 'text', state: 'Pro', isBadge: true },
            { type: 'color', state: '#fff' },
            { type: 'text', state: null, isCopyable: true },
        ]) {
            expect(mountEntry(entry).find('button').exists()).toBe(false);
        }
    });
});
