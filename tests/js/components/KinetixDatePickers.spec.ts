import { config, mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { afterEach, describe, expect, it, vi } from 'vitest';
import KinetixDatePicker from '@/components/KinetixDatePicker.vue';
import KinetixDateTimePicker from '@/components/KinetixDateTimePicker.vue';
import { i18n } from './i18n';

config.global.plugins = [i18n];

/**
 * Reka's Calendar is awkward to drive in happy-dom — stub it with one button
 * that "picks" a fixed day, mirroring the `value`/`update:value` contract.
 */
const CalendarStub = defineComponent({
    props: { value: { type: String, default: null } },
    emits: ['update:value'],
    setup(props, { emit }) {
        return () =>
            h(
                'button',
                {
                    'data-test': 'pick-day',
                    type: 'button',
                    onClick: () => emit('update:value', '2026-08-15'),
                },
                props.value ?? 'none',
            );
    },
});

const bodyButton = (text: string): HTMLButtonElement | undefined =>
    Array.from(document.querySelectorAll('button')).find(
        (b) => b.textContent?.trim() === text,
    ) as HTMLButtonElement | undefined;

/**
 * Reka keeps closing popover content mounted (data-state="closed") until the
 * exit animation ends — which happy-dom never fires. "Closed" therefore means
 * gone OR inside a data-state="closed" subtree.
 */
const isClosedOrGone = (el: Element | null | undefined): boolean =>
    !el || el.closest('[data-state="closed"]') !== null;

const mountOpenDatePicker = async (props: Record<string, unknown> = {}) => {
    const wrapper = mount(KinetixDatePicker, {
        props,
        attachTo: document.body,
        global: { stubs: { KinetixCalendar: CalendarStub } },
    });

    await wrapper.get('button').trigger('click');
    await wrapper.vm.$nextTick();

    return wrapper;
};

afterEach(() => {
    document.body.innerHTML = '';
});

describe('KinetixDatePicker', () => {
    it('renders a native date input in native mode and emits the value', async () => {
        const wrapper = mount(KinetixDatePicker, { props: { native: true } });
        const input = wrapper.get('input[type="date"]');

        await input.setValue('2026-03-15');

        expect(wrapper.emitted('update:value')?.[0]).toEqual(['2026-03-15']);
    });

    it('renders the shadcn trigger (button) by default with the placeholder', () => {
        const wrapper = mount(KinetixDatePicker, {
            props: { placeholder: 'Pick a date' },
        });

        expect(wrapper.find('input[type="date"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Pick a date');
    });

    it('commits and closes on date select by default (shadcn behavior)', async () => {
        const wrapper = await mountOpenDatePicker();

        (
            document.querySelector('[data-test="pick-day"]') as HTMLElement
        ).click();
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('update:value')?.[0]).toEqual(['2026-08-15']);
        expect(
            isClosedOrGone(document.querySelector('[data-test="pick-day"]')),
        ).toBe(true);
        wrapper.unmount();
    });

    it('stays open when closeOnSelect is false', async () => {
        const wrapper = await mountOpenDatePicker({ closeOnSelect: false });

        (
            document.querySelector('[data-test="pick-day"]') as HTMLElement
        ).click();
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('update:value')?.[0]).toEqual(['2026-08-15']);
        expect(document.querySelector('[data-test="pick-day"]')).not.toBeNull();
        wrapper.unmount();
    });

    it('confirm mode drafts on select and only Apply commits', async () => {
        const wrapper = await mountOpenDatePicker({ confirm: true });

        (
            document.querySelector('[data-test="pick-day"]') as HTMLElement
        ).click();
        await wrapper.vm.$nextTick();

        // Draft only — nothing committed, popover still open.
        expect(wrapper.emitted('update:value')).toBeUndefined();

        bodyButton('Apply')!.click();
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('update:value')?.[0]).toEqual(['2026-08-15']);
        expect(
            isClosedOrGone(document.querySelector('[data-test="pick-day"]')),
        ).toBe(true);
        wrapper.unmount();
    });

    it('shows a Today shortcut that commits the current date', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        vi.setSystemTime(new Date(2026, 7, 5, 10, 0));

        const wrapper = await mountOpenDatePicker({ showToday: true });

        bodyButton('Today')!.click();
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('update:value')?.[0]).toEqual(['2026-08-05']);

        vi.useRealTimers();
        wrapper.unmount();
    });

    it('Today follows the configured timezone, not the browser', async () => {
        // 20:00 UTC is already tomorrow in Pacific/Kiritimati (UTC+14).
        vi.useFakeTimers({ shouldAdvanceTime: true });
        vi.setSystemTime(new Date(Date.UTC(2026, 7, 5, 20, 0)));

        const wrapper = await mountOpenDatePicker({
            showToday: true,
            timezone: 'Pacific/Kiritimati',
        });

        bodyButton('Today')!.click();
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('update:value')?.[0]).toEqual(['2026-08-06']);

        vi.useRealTimers();
        wrapper.unmount();
    });
});

describe('KinetixDateTimePicker', () => {
    it('renders a native datetime input in native mode and emits the value', async () => {
        const wrapper = mount(KinetixDateTimePicker, {
            props: { native: true },
        });
        const input = wrapper.get('input[type="datetime-local"]');

        await input.setValue('2026-03-15T13:30');

        expect(wrapper.emitted('update:value')?.[0]).toEqual([
            '2026-03-15T13:30',
        ]);
    });

    it('shows the placeholder when empty and a formatted value when set', () => {
        const empty = mount(KinetixDateTimePicker, {
            props: { placeholder: 'MM/DD/YYYY hh:mm' },
        });
        expect(empty.text()).toContain('MM/DD/YYYY hh:mm');

        const filled = mount(KinetixDateTimePicker, {
            props: { value: '2026-03-15T13:30', locale: 'en-US' },
        });
        // Localized datetime — just assert it isn't the placeholder and has digits.
        expect(filled.text()).not.toContain('MM/DD/YYYY');
        expect(filled.text()).toMatch(/2026/);
    });

    const mountOpenDateTime = async (props: Record<string, unknown> = {}) => {
        const wrapper = mount(KinetixDateTimePicker, {
            props,
            attachTo: document.body,
            global: { stubs: { KinetixCalendar: CalendarStub } },
        });

        await wrapper.get('button').trigger('click');
        await wrapper.vm.$nextTick();

        return wrapper;
    };

    it('picking a date keeps the popover open (time still pending)', async () => {
        const wrapper = await mountOpenDateTime();

        (
            document.querySelector('[data-test="pick-day"]') as HTMLElement
        ).click();
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('update:value')?.[0]).toEqual([
            '2026-08-15T00:00',
        ]);
        expect(document.querySelector('[data-test="pick-day"]')).not.toBeNull();
        wrapper.unmount();
    });

    it('Now commits the current date and time rounded to minuteStep', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        vi.setSystemTime(new Date(2026, 7, 5, 14, 33));

        const wrapper = await mountOpenDateTime();

        bodyButton('Now')!.click();
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('update:value')?.[0]).toEqual([
            '2026-08-05T14:35',
        ]);

        vi.useRealTimers();
        wrapper.unmount();
    });

    it('Now follows the configured timezone, not the browser', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        vi.setSystemTime(new Date(Date.UTC(2026, 7, 5, 20, 0)));

        const wrapper = await mountOpenDateTime({
            timezone: 'Pacific/Kiritimati',
        });

        bodyButton('Now')!.click();
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('update:value')?.[0]).toEqual([
            '2026-08-06T10:00',
        ]);

        vi.useRealTimers();
        wrapper.unmount();
    });

    it('confirm mode drafts date + time and only Apply commits', async () => {
        const wrapper = await mountOpenDateTime({
            confirm: true,
            value: '2026-03-15T13:30',
        });

        (
            document.querySelector('[data-test="pick-day"]') as HTMLElement
        ).click();
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('update:value')).toBeUndefined();

        bodyButton('Apply')!.click();
        await wrapper.vm.$nextTick();

        // The drafted date with the previously stored time, committed at once.
        expect(wrapper.emitted('update:value')?.[0]).toEqual([
            '2026-08-15T13:30',
        ]);
        wrapper.unmount();
    });

    it('Done just dismisses in live mode without an extra emit', async () => {
        const wrapper = await mountOpenDateTime({ value: '2026-03-15T13:30' });

        bodyButton('Done')!.click();
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('update:value')).toBeUndefined();
        expect(
            isClosedOrGone(document.querySelector('[data-test="pick-day"]')),
        ).toBe(true);
        wrapper.unmount();
    });
});
