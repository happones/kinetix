import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
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

const mounted: { unmount(): void }[] = [];

const mountPicker = async (props: Record<string, unknown> = {}) => {
    const wrapper = mount(KinetixTimezonePicker, {
        props,
        global: { plugins: [i18n] },
        attachTo: document.body,
    });
    mounted.push(wrapper);
    await nextTick();

    return wrapper;
};

// A short list across three regions: the picker tests that only look at
// grouping, filtering and selection need not render every IANA zone.
const stubZones = (): void => {
    vi.spyOn(Intl, 'supportedValuesOf').mockReturnValue([
        'America/Bogota',
        'America/Mexico_City',
        'America/New_York',
        'Asia/Kolkata',
        'Asia/Tokyo',
        'Europe/London',
        'Europe/Madrid',
    ]);
};

const openPicker = async (w: Awaited<ReturnType<typeof mountPicker>>) => {
    await w.find('button').trigger('click');
    await nextTick();
};

describe('KinetixTimezonePicker', () => {
    afterEach(() => {
        mounted.splice(0).forEach((wrapper) => wrapper.unmount());
        document.body.innerHTML = '';
        vi.restoreAllMocks();
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
        stubZones();
        const w = await mountPicker({ regions: ['America'] });
        await openPicker(w);

        const text = document.body.textContent ?? '';
        expect(text).toContain('America');
        expect(text).not.toContain('Europe');
        expect(text).not.toContain('Asia');
    });

    it('groups options under region headings by default', async () => {
        stubZones();
        const w = await mountPicker({ regions: ['America', 'Europe'] });
        await openPicker(w);

        const text = document.body.textContent ?? '';
        expect(text).toContain('America');
        expect(text).toContain('Europe');
    });

    it('renders a flat list without region headings when groupByRegion is false', async () => {
        stubZones();
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
        stubZones();
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

    describe('the shared zone list', () => {
        // A distinct hour per test, so the module cache starts cold.
        let hour = 600_000;

        const freshHour = (): void => {
            hour += 10;
            vi.useFakeTimers({ toFake: ['Date'] });
            vi.setSystemTime(hour * 3_600_000);
        };

        afterEach(() => {
            vi.useRealTimers();
            vi.restoreAllMocks();
        });

        it('is never built at mount, even with a selection', async () => {
            freshHour();
            const supported = vi.spyOn(Intl, 'supportedValuesOf');

            for (let i = 0; i < 10; i++) {
                await mountPicker({ modelValue: 'Europe/Madrid' });
            }

            expect(supported).not.toHaveBeenCalled();
        });

        it('is built once on the first open and shared within the hour', async () => {
            freshHour();
            const supported = vi.spyOn(Intl, 'supportedValuesOf');

            const first = await mountPicker({ regions: ['America'] });
            await openPicker(first);
            expect(supported).toHaveBeenCalledTimes(1);

            const second = await mountPicker({ regions: ['Europe'] });
            await openPicker(second);
            expect(supported).toHaveBeenCalledTimes(1);
            // Per-instance region filtering over the shared list.
            expect(
                document.body.querySelectorAll('[role="listbox"]')[1]
                    ?.textContent,
            ).not.toContain('Mexico City');
        });

        it('is rebuilt on an open in a later hour', async () => {
            freshHour();
            const supported = vi.spyOn(Intl, 'supportedValuesOf');

            const picker = await mountPicker({ regions: ['America'] });
            await openPicker(picker);
            expect(supported).toHaveBeenCalledTimes(1);

            await openPicker(picker);
            vi.setSystemTime((hour + 1) * 3_600_000);
            await openPicker(picker);
            expect(supported).toHaveBeenCalledTimes(2);
        });

        it('keeps the selected label right without the list', async () => {
            freshHour();
            const w = await mountPicker({
                modelValue: 'America/Mexico_City',
            });

            expect(w.text()).toContain('Mexico City (UTC-06:00)');
        });

        it('shows no selection for an unknown zone or one outside the regions', async () => {
            freshHour();
            const unknown = await mountPicker({ modelValue: 'Mars/Olympus' });
            expect(unknown.text()).toContain('Select a timezone…');

            const filtered = await mountPicker({
                modelValue: 'Europe/Madrid',
                regions: ['America'],
            });
            expect(filtered.text()).toContain('Select a timezone…');
        });
    });
});
