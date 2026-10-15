import { config, mount } from '@vue/test-utils';
import { defineComponent, h, nextTick } from 'vue';
import { afterEach, describe, expect, it } from 'vitest';
import KinetixDatePicker from '@/components/KinetixDatePicker.vue';
import KinetixDateTimePicker from '@/components/KinetixDateTimePicker.vue';
import KinetixMaskedDateInput from '@/components/KinetixMaskedDateInput.vue';
import KinetixMonthPicker from '@/components/KinetixMonthPicker.vue';
import KinetixTimePicker from '@/components/KinetixTimePicker.vue';
import { i18n } from './i18n';

config.global.plugins = [i18n];

/** One button that "picks" a fixed day, like the other picker specs. */
const CalendarStub = defineComponent({
    props: { value: { type: String, default: null } },
    emits: ['update:value'],
    setup(_, { emit }) {
        return () =>
            h(
                'button',
                {
                    'data-test': 'pick-day',
                    type: 'button',
                    onClick: () => emit('update:value', '2026-08-15'),
                },
                'pick',
            );
    },
});

const type = async (
    wrapper: ReturnType<typeof mount>,
    text: string,
): Promise<void> => {
    const input = wrapper.get('input');
    (input.element as HTMLInputElement).value = text;
    await input.trigger('input');
};

afterEach(() => {
    document.body.innerHTML = '';
});

describe('KinetixMaskedDateInput', () => {
    it('lays the typed digits on the format and hands over the ISO value', async () => {
        const wrapper = mount(KinetixMaskedDateInput, {
            props: { id: 'starts_on', format: 'dd/mm/yyyy' },
        });
        const input = wrapper.get('input');

        expect(input.attributes('placeholder')).toBe('DD/MM/YYYY');
        expect(input.attributes('inputmode')).toBe('numeric');

        await type(wrapper, '1503');
        expect((input.element as HTMLInputElement).value).toBe('15/03');
        expect(wrapper.emitted('update:value')).toBeUndefined();

        await type(wrapper, '15032026');
        expect((input.element as HTMLInputElement).value).toBe('15/03/2026');
        expect(wrapper.emitted('update:value')?.at(-1)).toEqual(['2026-03-15']);
    });

    it('says when the date does not exist, and empties the field meanwhile', async () => {
        const wrapper = mount(KinetixMaskedDateInput, {
            props: {
                id: 'starts_on',
                format: 'dd/mm/yyyy',
                value: '2026-03-15',
            },
        });

        expect((wrapper.get('input').element as HTMLInputElement).value).toBe(
            '15/03/2026',
        );

        await type(wrapper, '31022026');

        expect(wrapper.get('input').attributes('aria-invalid')).toBe('true');
        expect(wrapper.get('#starts_on-mask-error').text()).toBe(
            "That date doesn't exist.",
        );
        expect(wrapper.get('input').attributes('aria-describedby')).toContain(
            'starts_on-mask-error',
        );
        // The stale 15/03 must not submit behind the text the reader sees.
        expect(wrapper.emitted('update:value')?.at(-1)).toEqual([null]);
    });

    it('keeps a typed date inside the bounds', async () => {
        const wrapper = mount(KinetixMaskedDateInput, {
            props: {
                id: 'd',
                format: 'yyyy-mm-dd',
                minValue: '2026-01-01',
                maxValue: '2026-12-31',
            },
        });

        await type(wrapper, '20270101');

        expect(wrapper.text()).toContain(
            'That date is outside the allowed range.',
        );
        expect(wrapper.emitted('update:value')).toBeUndefined();
    });

    it('asks to finish a half-typed value on blur', async () => {
        const wrapper = mount(KinetixMaskedDateInput, {
            props: { id: 'd', format: 'mm/dd/yyyy' },
        });

        await type(wrapper, '0315');
        await wrapper.get('input').trigger('blur');

        expect(wrapper.text()).toContain('Finish typing it as MM/DD/YYYY.');
    });

    it('shows a value set from outside, and joins the field wiring', async () => {
        const wrapper = mount(KinetixMaskedDateInput, {
            props: { id: 'd', format: 'dd.mm.yy', value: null },
            attrs: { 'aria-describedby': 'd-help' },
        });

        await wrapper.setProps({ value: '2026-11-22' });

        const input = wrapper.get('input');
        expect((input.element as HTMLInputElement).value).toBe('22.11.26');
        expect(input.attributes('aria-describedby')).toBe(
            'd-help d-mask-format',
        );
    });

    it('takes AM/PM as a letter, so the keyboard keeps its letters', async () => {
        const wrapper = mount(KinetixMaskedDateInput, {
            props: { id: 't', kind: 'time', format: 'hh:mm a' },
        });

        expect(wrapper.get('input').attributes('inputmode')).toBe('text');

        await type(wrapper, '0730p');

        expect((wrapper.get('input').element as HTMLInputElement).value).toBe(
            '07:30 PM',
        );
        expect(wrapper.emitted('update:value')?.at(-1)).toEqual(['19:30']);
    });
});

describe('typed pickers', () => {
    it('a typed date picker types the date and keeps the calendar on a button', async () => {
        const wrapper = mount(KinetixDatePicker, {
            props: { id: 'starts_on', typed: true, inputFormat: 'dd/mm/yyyy' },
            attrs: { 'aria-invalid': 'true' },
            attachTo: document.body,
            global: { stubs: { KinetixCalendar: CalendarStub } },
        });

        const input = wrapper.get('input#starts_on');
        expect(input.attributes('aria-invalid')).toBe('true');

        await type(wrapper, '01022026');
        expect(wrapper.emitted('update:value')?.at(-1)).toEqual(['2026-02-01']);

        // The calendar still picks, and the mask shows what it picked.
        await wrapper.get('button[aria-label="Pick a date"]').trigger('click');
        await nextTick();
        (
            document.querySelector('[data-test="pick-day"]') as HTMLElement
        ).click();
        await nextTick();

        expect(wrapper.emitted('update:value')?.at(-1)).toEqual(['2026-08-15']);
        await wrapper.setProps({ value: '2026-08-15' });
        expect((input.element as HTMLInputElement).value).toBe('15/08/2026');
    });

    it('a typed datetime picker follows its 12-hour clock by default', () => {
        const wrapper = mount(KinetixDateTimePicker, {
            props: {
                typed: true,
                hour12: true,
                locale: 'es-MX',
                value: '2026-03-15T19:05',
            },
        });

        const input = wrapper.get('input');
        expect(input.attributes('placeholder')).toBe('DD/MM/YYYY HH:MM AM');
        expect((input.element as HTMLInputElement).value).toBe(
            '15/03/2026 07:05 PM',
        );
        expect(
            wrapper.find('button[aria-label="Pick a date and time"]').exists(),
        ).toBe(true);
    });

    it('a typed time picker names its input after its aria label', async () => {
        const wrapper = mount(KinetixTimePicker, {
            props: {
                typed: true,
                hour12: false,
                ariaLabel: 'Opens',
            },
        });

        const input = wrapper.get('input');
        expect(input.attributes('aria-label')).toBe('Opens');
        expect(input.attributes('placeholder')).toBe('HH:MM');

        await type(wrapper, '0930');
        expect(wrapper.emitted('update:value')?.at(-1)).toEqual(['09:30']);
    });

    it('a typed month picker stores Y-m', async () => {
        const wrapper = mount(KinetixMonthPicker, {
            props: { typed: true, inputFormat: 'mm/yyyy' },
        });

        await type(wrapper, '112026');

        expect(wrapper.emitted('update:value')?.at(-1)).toEqual(['2026-11']);
    });

    it('the button trigger stays the default', () => {
        const wrapper = mount(KinetixDatePicker, { props: { id: 'd' } });

        expect(wrapper.find('input').exists()).toBe(false);
        expect(wrapper.get('button#d').exists()).toBe(true);
    });
});
