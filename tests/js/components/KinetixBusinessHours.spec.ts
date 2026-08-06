import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { createI18n } from 'vue-i18n';

import KinetixBusinessHours from '@/components/KinetixBusinessHours.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: {
        en: {
            kinetix: {
                business_hours_add_range: 'Add hours',
                business_hours_remove_range: 'Remove hours',
                business_hours_apply_all: 'Apply to all days',
                business_hours_closed: 'Closed',
            },
        },
    },
});

const week = (overrides: Record<string, any> = {}) => ({
    monday: {
        enabled: true,
        ranges: [{ start: '09:00', end: '17:00' }],
    },
    ...overrides,
});

const mountEditor = (value: any = null) =>
    mount(KinetixBusinessHours, {
        props: { value },
        global: { plugins: [i18n] },
    });

describe('KinetixBusinessHours', () => {
    it('renders seven day rows and normalizes a partial value', () => {
        const wrapper = mountEditor(week());

        expect(wrapper.findAll('[role="switch"]')).toHaveLength(7);
        expect(wrapper.text()).toContain('Monday');
        expect(wrapper.text()).toContain('Sunday');
        // Six days arrive missing → closed.
        expect(wrapper.text().match(/Closed/g)).toHaveLength(6);
        // Monday shows its range inputs.
        const times = wrapper.findAll('input[type="time"]');
        expect(times).toHaveLength(2);
        expect((times[0].element as HTMLInputElement).value).toBe('09:00');
    });

    it('emits an updated full week when toggling a day', async () => {
        const wrapper = mountEditor(week());

        // Toggle Tuesday (index 1) on.
        await wrapper.findAll('[role="switch"]')[1].trigger('click');

        const emitted = wrapper.emitted('update:value')!.at(-1)![0] as any;
        expect(emitted.tuesday.enabled).toBe(true);
        // The seed range comes with it, and the rest of the week is intact.
        expect(emitted.tuesday.ranges[0]).toEqual({
            start: '09:00',
            end: '17:00',
        });
        expect(emitted.monday.enabled).toBe(true);
        expect(Object.keys(emitted)).toHaveLength(7);
    });

    it('applies one day to the whole week', async () => {
        const wrapper = mountEditor(
            week({
                monday: {
                    enabled: true,
                    ranges: [{ start: '10:00', end: '14:00' }],
                },
            }),
        );

        const apply = wrapper
            .findAll('button')
            .find((b) => b.text().includes('Apply to all days'))!;
        await apply.trigger('click');

        const emitted = wrapper.emitted('update:value')!.at(-1)![0] as any;

        for (const day of Object.keys(emitted)) {
            expect(emitted[day].enabled).toBe(true);
            expect(emitted[day].ranges).toEqual([
                { start: '10:00', end: '14:00' },
            ]);
        }
    });

    it('adds and edits ranges immutably', async () => {
        const wrapper = mountEditor(week());

        const add = wrapper
            .findAll('button')
            .find((b) => b.text().includes('Add hours'))!;
        await add.trigger('click');

        let emitted = wrapper.emitted('update:value')!.at(-1)![0] as any;
        expect(emitted.monday.ranges).toHaveLength(2);

        // Editing the first start time emits the change without mutating props.
        await wrapper.find('input[type="time"]').setValue('08:30');

        emitted = wrapper.emitted('update:value')!.at(-1)![0] as any;
        expect(emitted.monday.ranges[0].start).toBe('08:30');
    });
});
