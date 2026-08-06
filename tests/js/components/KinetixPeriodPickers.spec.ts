import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import KinetixMonthPicker from '@/components/KinetixMonthPicker.vue';
import KinetixWeekPicker from '@/components/KinetixWeekPicker.vue';
import KinetixYearPicker from '@/components/KinetixYearPicker.vue';
import { i18n } from './i18n';

/**
 * Fixed instant: 2026-08-05 20:00 UTC → already 2026-08-06 in
 * Pacific/Kiritimati (UTC+14). The current-period presets must follow the
 * configured timezone, never the browser clock.
 */
const INSTANT = new Date(Date.UTC(2026, 7, 5, 20, 0));

const bodyButton = (text: string): HTMLButtonElement | undefined =>
    Array.from(document.querySelectorAll('button')).find(
        (b) => b.textContent?.trim() === text,
    ) as HTMLButtonElement | undefined;

const mountOpen = async (
    component:
        | typeof KinetixMonthPicker
        | typeof KinetixWeekPicker
        | typeof KinetixYearPicker,
    props: Record<string, any> = {},
) => {
    const wrapper = mount(component, {
        props,
        attachTo: document.body,
        global: { plugins: [i18n] },
    });

    await wrapper.get('button').trigger('click');
    await wrapper.vm.$nextTick();

    return wrapper;
};

afterEach(() => {
    vi.useRealTimers();
    document.body.innerHTML = '';
});

describe('period pickers — presets, confirm, closeOnSelect', () => {
    it('MonthPicker: This month follows the configured timezone', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        vi.setSystemTime(INSTANT);

        const w = await mountOpen(KinetixMonthPicker, {
            showToday: true,
            timezone: 'Pacific/Kiritimati',
        });

        bodyButton('This month')!.click();
        await w.vm.$nextTick();

        expect(w.emitted('update:value')?.[0]).toEqual(['2026-08']);
        w.unmount();
    });

    it('YearPicker: This year follows the configured timezone', async () => {
        // 23:30 UTC on Dec 31 2026 is already 2027 in Kiritimati.
        vi.useFakeTimers({ shouldAdvanceTime: true });
        vi.setSystemTime(new Date(Date.UTC(2026, 11, 31, 23, 30)));

        const w = await mountOpen(KinetixYearPicker, {
            showToday: true,
            timezone: 'Pacific/Kiritimati',
        });

        bodyButton('This year')!.click();
        await w.vm.$nextTick();

        expect(w.emitted('update:value')?.[0]).toEqual(['2027']);
        w.unmount();
    });

    it('WeekPicker: This week emits the ISO week of the zoned today', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        vi.setSystemTime(INSTANT);

        const w = await mountOpen(KinetixWeekPicker, {
            showToday: true,
            timezone: 'UTC',
        });

        bodyButton('This week')!.click();
        await w.vm.$nextTick();

        // 2026-08-05 falls in ISO week 32.
        expect(w.emitted('update:value')?.[0]).toEqual(['2026-W32']);
        w.unmount();
    });

    it('MonthPicker: confirm mode drafts the click and only Apply commits', async () => {
        const w = await mountOpen(KinetixMonthPicker, {
            confirm: true,
            value: '2026-03',
        });

        // Month grid renders short month names; pick one by position: the
        // first grid button after the year nav is January of the view year.
        const gridButtons = Array.from(
            document.querySelectorAll('.grid button'),
        ) as HTMLButtonElement[];
        gridButtons[5]!.click(); // June
        await w.vm.$nextTick();

        expect(w.emitted('update:value')).toBeUndefined();

        bodyButton('Apply')!.click();
        await w.vm.$nextTick();

        expect(w.emitted('update:value')?.[0]).toEqual(['2026-06']);
        w.unmount();
    });

    it('YearPicker: stays open when closeOnSelect is false', async () => {
        const w = await mountOpen(KinetixYearPicker, {
            closeOnSelect: false,
            value: '2026',
        });

        bodyButton('2027')!.click();
        await w.vm.$nextTick();

        expect(w.emitted('update:value')?.[0]).toEqual(['2027']);
        // Still open: the year grid remains interactive in the document.
        expect(bodyButton('2027')).toBeDefined();
        w.unmount();
    });
});
