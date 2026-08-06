import { mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';
import KinetixDateRangePicker from '@/components/KinetixDateRangePicker.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    messages: {
        en: {
            kinetix: {
                pick_date_range: 'Pick a date range',
                calendar_today: 'Today',
                apply: 'Apply',
            },
        },
    },
});

const mountWith = (props: Record<string, any>) =>
    mount(KinetixDateRangePicker, { props, global: { plugins: [i18n] } });

/** Stub the Reka range calendar with one button that "picks" a fixed range. */
const RangeCalendarStub = defineComponent({
    props: { value: { type: Object, default: null } },
    emits: ['update:value'],
    setup(props, { emit }) {
        return () =>
            h(
                'button',
                {
                    'data-test': 'pick-range',
                    type: 'button',
                    onClick: () =>
                        emit('update:value', {
                            from: '2026-08-10',
                            to: '2026-08-20',
                        }),
                },
                JSON.stringify(props.value ?? null),
            );
    },
});

const bodyButton = (text: string): HTMLButtonElement | undefined =>
    Array.from(document.querySelectorAll('button')).find(
        (b) => b.textContent?.trim() === text,
    ) as HTMLButtonElement | undefined;

const isClosedOrGone = (el: Element | null | undefined): boolean =>
    !el || el.closest('[data-state="closed"]') !== null;

const mountOpen = async (props: Record<string, any> = {}) => {
    const wrapper = mount(KinetixDateRangePicker, {
        props,
        attachTo: document.body,
        global: {
            plugins: [i18n],
            stubs: { KinetixRangeCalendar: RangeCalendarStub },
        },
    });

    await wrapper.get('button').trigger('click');
    await wrapper.vm.$nextTick();

    return wrapper;
};

afterEach(() => {
    document.body.innerHTML = '';
});

describe('KinetixDateRangePicker', () => {
    it('native renders two date inputs and emits {from,to}', async () => {
        const w = mountWith({
            native: true,
            value: { from: '2026-06-01', to: null },
        });
        const inputs = w.findAll('input[type="date"]');
        expect(inputs).toHaveLength(2);

        await inputs[1].setValue('2026-06-30');
        await inputs[1].trigger('change');

        expect(w.emitted('update:value')?.[0]).toEqual([
            { from: '2026-06-01', to: '2026-06-30' },
        ]);
    });

    it('native passes min/max bounds to both inputs', () => {
        const w = mountWith({
            native: true,
            minValue: '2026-01-01',
            maxValue: '2026-12-31',
        });
        const inputs = w.findAll('input[type="date"]');
        expect(inputs[0].attributes('min')).toBe('2026-01-01');
        expect(inputs[1].attributes('max')).toBe('2026-12-31');
    });

    it('shadcn trigger shows the formatted range', () => {
        const w = mountWith({
            value: { from: '2026-06-01', to: '2026-06-30' },
        });
        const text = w.find('button').text();
        expect(text).toContain('–');
        expect(text).toContain('2026');
    });

    it('commits and closes once both ends are picked (default)', async () => {
        const w = await mountOpen();

        (
            document.querySelector('[data-test="pick-range"]') as HTMLElement
        ).click();
        await w.vm.$nextTick();

        expect(w.emitted('update:value')?.[0]).toEqual([
            { from: '2026-08-10', to: '2026-08-20' },
        ]);
        expect(
            isClosedOrGone(document.querySelector('[data-test="pick-range"]')),
        ).toBe(true);
        w.unmount();
    });

    it('stays open when closeOnSelect is false', async () => {
        const w = await mountOpen({ closeOnSelect: false });

        (
            document.querySelector('[data-test="pick-range"]') as HTMLElement
        ).click();
        await w.vm.$nextTick();

        expect(w.emitted('update:value')).toHaveLength(1);
        expect(
            document.querySelector('[data-test="pick-range"]'),
        ).not.toBeNull();
        w.unmount();
    });

    it('confirm mode drafts the range and only Apply commits', async () => {
        const w = await mountOpen({ confirm: true });

        (
            document.querySelector('[data-test="pick-range"]') as HTMLElement
        ).click();
        await w.vm.$nextTick();

        expect(w.emitted('update:value')).toBeUndefined();

        bodyButton('Apply')!.click();
        await w.vm.$nextTick();

        expect(w.emitted('update:value')?.[0]).toEqual([
            { from: '2026-08-10', to: '2026-08-20' },
        ]);
        expect(
            isClosedOrGone(document.querySelector('[data-test="pick-range"]')),
        ).toBe(true);
        w.unmount();
    });

    it('Today follows the configured timezone, not the browser', async () => {
        // 20:00 UTC is already tomorrow in Pacific/Kiritimati (UTC+14).
        vi.useFakeTimers({ shouldAdvanceTime: true });
        vi.setSystemTime(new Date(Date.UTC(2026, 7, 5, 20, 0)));

        const w = await mountOpen({
            showToday: true,
            timezone: 'Pacific/Kiritimati',
        });

        bodyButton('Today')!.click();
        await w.vm.$nextTick();

        expect(w.emitted('update:value')?.[0]).toEqual([
            { from: '2026-08-06', to: '2026-08-06' },
        ]);

        vi.useRealTimers();
        w.unmount();
    });
});
