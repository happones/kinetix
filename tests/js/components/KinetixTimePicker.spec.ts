import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import KinetixTimePicker from '@/components/KinetixTimePicker.vue';
import { i18n } from './i18n';

const mountWith = (props: Record<string, any>) =>
    mount(KinetixTimePicker, { props, global: { plugins: [i18n] } });

const bodyButton = (text: string): HTMLButtonElement | undefined =>
    Array.from(document.querySelectorAll('button')).find(
        (b) => b.textContent?.trim() === text,
    ) as HTMLButtonElement | undefined;

const mountOpen = async (props: Record<string, any> = {}) => {
    const wrapper = mount(KinetixTimePicker, {
        props,
        attachTo: document.body,
        global: { plugins: [i18n] },
    });

    await wrapper.get('button').trigger('click');
    await wrapper.vm.$nextTick();

    return wrapper;
};

afterEach(() => {
    document.body.innerHTML = '';
});

describe('KinetixTimePicker', () => {
    it('renders a native time input when native', () => {
        const w = mountWith({ native: true, value: '14:30' });
        const input = w.find('input[type="time"]');
        expect(input.exists()).toBe(true);
        expect((input.element as HTMLInputElement).value).toBe('14:30');
    });

    it('defaults to a 12-hour trigger label (AM/PM)', () => {
        // 14:30 stored (24h) → shown as 02:30 PM by default.
        const w = mountWith({ value: '14:30' });
        expect(w.find('button').text()).toContain('02:30 PM');
    });

    it('shows a 24-hour label when hour12 is false', () => {
        const w = mountWith({ value: '14:30', hour12: false });
        expect(w.find('button').text()).toContain('14:30');
        expect(w.find('button').text()).not.toContain('PM');
    });

    it('shows the placeholder when empty', () => {
        const w = mountWith({});
        expect(w.find('button').text()).toContain('Pick a time');
    });

    it('commits live on column clicks by default', async () => {
        const w = await mountOpen({ value: '14:30' });

        // 12h default: clicking hour "03" in the PM half → 15:30.
        bodyButton('03')!.click();
        await w.vm.$nextTick();

        expect(w.emitted('update:value')?.[0]).toEqual(['15:30']);
        w.unmount();
    });

    it('confirm mode drafts clicks and only Apply commits', async () => {
        const w = await mountOpen({ value: '14:30', confirm: true });

        bodyButton('03')!.click();
        await w.vm.$nextTick();
        expect(w.emitted('update:value')).toBeUndefined();

        bodyButton('Apply')!.click();
        await w.vm.$nextTick();

        expect(w.emitted('update:value')?.[0]).toEqual(['15:30']);
        w.unmount();
    });

    it('Now commits the current time rounded to minuteStep', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        vi.setSystemTime(new Date(2026, 7, 5, 14, 33));

        const w = await mountOpen({});

        bodyButton('Now')!.click();
        await w.vm.$nextTick();

        expect(w.emitted('update:value')?.[0]).toEqual(['14:35']);

        vi.useRealTimers();
        w.unmount();
    });

    it('Now reads the clock in the configured timezone, not the browser', async () => {
        // 20:00 UTC = 10:00 (next day) in Pacific/Kiritimati (UTC+14).
        vi.useFakeTimers({ shouldAdvanceTime: true });
        vi.setSystemTime(new Date(Date.UTC(2026, 7, 5, 20, 0)));

        const w = await mountOpen({ timezone: 'Pacific/Kiritimati' });

        bodyButton('Now')!.click();
        await w.vm.$nextTick();

        expect(w.emitted('update:value')?.[0]).toEqual(['10:00']);

        vi.useRealTimers();
        w.unmount();
    });

    it('Done dismisses without an extra emit in live mode', async () => {
        const w = await mountOpen({ value: '14:30' });

        bodyButton('Done')!.click();
        await w.vm.$nextTick();

        expect(w.emitted('update:value')).toBeUndefined();

        // Reka keeps closing content mounted (data-state="closed") until the
        // exit animation ends — "closed" means gone OR inside such a subtree.
        const now = bodyButton('Now');
        expect(!now || now.closest('[data-state="closed"]') !== null).toBe(
            true,
        );
        w.unmount();
    });

    it('an abandoned confirm draft does not leak into the next opening', async () => {
        const w = await mountOpen({ value: '14:30', confirm: true });

        bodyButton('03')!.click();
        await w.vm.$nextTick();

        // Dismiss WITHOUT applying (Escape/outside-click path).
        await w.get('button').trigger('click');
        await w.vm.$nextTick();

        // Reopen: the columns show the committed value again (02 PM active).
        await w.get('button').trigger('click');
        await w.vm.$nextTick();

        const active = Array.from(
            document.querySelectorAll('button[aria-pressed="true"]'),
        ).map((b) => b.textContent?.trim());

        expect(active).toContain('02');
        expect(w.emitted('update:value')).toBeUndefined();
        w.unmount();
    });
});
