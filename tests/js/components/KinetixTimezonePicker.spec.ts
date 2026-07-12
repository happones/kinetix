import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import { createI18n } from 'vue-i18n';
import KinetixTimezonePicker from '@/components/KinetixTimezonePicker.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: {
        en: {
            kinetix: {
                timezone_placeholder: 'Select a timezone…',
                timezone_search_placeholder: 'Search timezones…',
                timezone_empty: 'No timezone found.',
                timezone_clear: 'Clear',
                timezone_region_america: 'America',
                timezone_region_europe: 'Europe',
                timezone_region_asia: 'Asia',
            },
        },
    },
});

const mountPicker = async (props: Record<string, unknown> = {}) => {
    const wrapper = mount(KinetixTimezonePicker, {
        props,
        global: { plugins: [i18n] },
        attachTo: document.body,
    });
    await nextTick();

    return wrapper;
};

const openPicker = async (w: Awaited<ReturnType<typeof mountPicker>>) => {
    await w.find('button').trigger('click');
    await nextTick();
};

describe('KinetixTimezonePicker', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('shows the placeholder when nothing is selected', async () => {
        const w = await mountPicker();
        expect(w.text()).toContain('Select a timezone…');
    });

    it('shows the selected zone as "name (offset)" by default', async () => {
        const w = await mountPicker({ modelValue: 'America/Mexico_City' });
        expect(w.text()).toContain('Mexico City (UTC-06:00)');
    });

    it('shows only the offset when display="offset"', async () => {
        const w = await mountPicker({
            modelValue: 'America/Mexico_City',
            display: 'offset',
        });
        expect(w.text()).toContain('UTC-06:00');
        expect(w.text()).not.toContain('Mexico City');
    });

    it('shows only the name when display="name"', async () => {
        const w = await mountPicker({
            modelValue: 'America/Mexico_City',
            display: 'name',
        });
        expect(w.text()).toContain('Mexico City');
        expect(w.text()).not.toContain('UTC-06:00');
    });

    it('restricts the option list to the given regions', async () => {
        const w = await mountPicker({ regions: ['America'] });
        await openPicker(w);

        const text = document.body.textContent ?? '';
        expect(text).toContain('America');
        expect(text).not.toContain('Europe');
        expect(text).not.toContain('Asia');
    });

    it('groups options under region headings by default', async () => {
        const w = await mountPicker({ regions: ['America', 'Europe'] });
        await openPicker(w);

        const text = document.body.textContent ?? '';
        expect(text).toContain('America');
        expect(text).toContain('Europe');
    });

    it('renders a flat list without region headings when groupByRegion is false', async () => {
        const w = await mountPicker({
            regions: ['America'],
            groupByRegion: false,
        });
        await openPicker(w);

        expect(
            document.body.querySelector('[role="listbox"]')?.textContent,
        ).not.toContain('America');
    });

    it('emits update:modelValue with the IANA id when an option is selected', async () => {
        const w = await mountPicker({ regions: ['America'] });
        await openPicker(w);

        const option = [
            ...document.body.querySelectorAll('[role="option"]'),
        ].find((el) => el.textContent?.includes('Mexico City')) as HTMLElement;
        option.click();
        await nextTick();

        expect(w.emitted('update:modelValue')?.[0]).toEqual([
            'America/Mexico_City',
        ]);
    });

    it('shows a clear button only once a zone is selected, and clears on click', async () => {
        const empty = await mountPicker({ clearable: true });
        expect(empty.find('button[aria-label="Clear"]').exists()).toBe(false);

        const w = await mountPicker({
            modelValue: 'America/Mexico_City',
            clearable: true,
        });
        await w.find('button[aria-label="Clear"]').trigger('click');
        expect(w.emitted('update:modelValue')?.[0]).toEqual([null]);
    });

    it('shows a live current time next to the selection when showCurrentTime is set', async () => {
        const w = await mountPicker({
            modelValue: 'America/Mexico_City',
            showCurrentTime: true,
        });
        // A short time string like "3:45 PM" should be present alongside the label.
        expect(w.text()).toMatch(/\d{1,2}:\d{2}\s?(AM|PM)/);
    });

    it('does not show a current time when showCurrentTime is false', async () => {
        const w = await mountPicker({ modelValue: 'America/Mexico_City' });
        expect(w.text()).not.toMatch(/\d{1,2}:\d{2}\s?(AM|PM)/);
    });
});
