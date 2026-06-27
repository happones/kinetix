import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { createI18n } from 'vue-i18n';
import KinetixTimePicker from '@/components/KinetixTimePicker.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    messages: { en: { kinetix: { pick_time: 'Pick a time' } } },
});

const mountWith = (props: Record<string, any>) =>
    mount(KinetixTimePicker, { props, global: { plugins: [i18n] } });

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
});
