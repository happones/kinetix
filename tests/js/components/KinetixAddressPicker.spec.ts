import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { createI18n } from 'vue-i18n';

// The country select (KinetixCombobox) reads usePage() at setup.
vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));
const fetchMock = vi.fn();
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));

import KinetixAddressPicker from '@/components/KinetixAddressPicker.vue';
import { clearKinetixLocationsCache } from '@/composables/useKinetixLocations';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    messages: {
        en: {
            kinetix: {
                address_line1: 'Address line 1',
                address_line2: 'Address line 2',
                address_city: 'City',
                address_state: 'State / Province',
                address_postal: 'Postal code',
                address_country: 'Country',
                address_choose_country_first: 'Choose a country first',
                address_choose_state_first: 'Choose a state first',
                address_choose_city_first: 'Choose a city first',
                address_neighborhood: 'Neighborhood',
                address_postal_code_not_found: 'No place has that postal code.',
                address_postal_code_found: '{place}. Choose the neighborhood.',
                spotlight_placeholder: 'Search…',
                spotlight_empty: 'No results.',
            },
        },
    },
});

const mountWith = (props: Record<string, any>) =>
    mount(KinetixAddressPicker, { props, global: { plugins: [i18n] } });

describe('KinetixAddressPicker', () => {
    it('renders a text input per sub-field plus the country select', () => {
        const w = mountWith({
            value: { city: 'Austin' },
            countries: { US: 'United States', MX: 'Mexico' },
        });

        // line1, line2, city, state, postalCode = 5 text inputs (country is a combobox).
        expect(w.findAll('input[type="text"]')).toHaveLength(5);
        expect(
            (w.find('[id$="-city"]').element as HTMLInputElement).value,
        ).toBe('Austin');
    });

    it('honors a restricted/ordered fields list', () => {
        const w = mountWith({ fields: ['city', 'country'] });
        expect(w.findAll('input[type="text"]')).toHaveLength(1); // only city
        expect(w.find('[id$="-city"]').exists()).toBe(true);
        expect(w.find('[id$="-line1"]').exists()).toBe(false);
    });

    it('omits the country select when country is not in fields', () => {
        const w = mountWith({
            fields: ['line1', 'line2', 'city', 'state', 'postalCode'],
            countries: { US: 'United States' },
        });

        expect(w.find('[id$="-country"]').exists()).toBe(false);
        expect(w.findAll('input[type="text"]')).toHaveLength(5);
    });

    it('emits the merged address object on input', async () => {
        const w = mountWith({ value: { city: 'Austin' } });
        const postal = w.find('[id$="-postalCode"]');
        await postal.setValue('73301');

        const events = w.emitted('update:value');
        expect(events).toBeTruthy();
        expect(events![events!.length - 1][0]).toMatchObject({
            city: 'Austin',
            postalCode: '73301',
        });
    });

    it('gives every picker its own ids, so labels work in a repeater', () => {
        const w = mount(
            defineComponent({
                setup: () => () =>
                    h('div', [
                        h(KinetixAddressPicker),
                        h(KinetixAddressPicker),
                    ]),
            }),
            { global: { plugins: [i18n] } },
        );
        const ids = w.findAll('[id$="-city"]').map((el) => el.attributes('id'));

        expect(ids).toHaveLength(2);
        expect(ids[0]).not.toBe(ids[1]);
    });

    it('marks the required parts', () => {
        const w = mountWith({ fields: ['line1', 'city'], required: ['city'] });

        expect(w.find('[id$="-city"]').attributes('required')).toBeDefined();
        expect(w.find('[id$="-line1"]').attributes('required')).toBeUndefined();
    });
});

describe('KinetixAddressPicker — locations mode', () => {
    const list = (labels: string[], complete = true) => ({
        options: labels.map((label, i) => ({ value: String(i + 1), label })),
        complete,
    });

    const located = (value: Record<string, unknown> | null) =>
        mountWith({
            value,
            fields: ['country', 'state', 'city'],
            countries: { MX: 'Mexico', MC: 'Monaco' },
            locationsToken: 'tok',
        });

    const lastValue = (w: ReturnType<typeof mountWith>) => {
        const events = w.emitted('update:value')!;

        return events[events.length - 1][0] as Record<string, unknown>;
    };

    beforeEach(() => {
        fetchMock.mockReset();
        clearKinetixLocationsCache();
    });

    it('waits for a country before listing states or cities', async () => {
        const w = located(null);
        await flushPromises();

        expect(fetchMock).not.toHaveBeenCalled();
        expect(w.find('[data-test="address-state-waiting"]').text()).toContain(
            'Choose a country first',
        );
    });

    it("lists the country's states, then the cities of the chosen state", async () => {
        fetchMock.mockImplementation((url: string) =>
            Promise.resolve(
                url.includes('level=states')
                    ? list(['Aguascalientes', 'Jalisco'])
                    : list(['Guadalajara', 'Zapopan']),
            ),
        );

        const w = located({ country: 'MX' });
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe(
            '/_kinetix/forms/locations?token=tok&level=states&country=MX',
        );
        expect(w.find('[data-test="address-city-waiting"]').text()).toContain(
            'Choose a state first',
        );

        w.findAllComponents({ name: 'KinetixCombobox' })[1].vm.$emit(
            'update:value',
            '2',
        );
        expect(lastValue(w)).toMatchObject({
            country: 'MX',
            stateId: '2',
            state: 'Jalisco',
            cityId: null,
        });

        await w.setProps({ value: lastValue(w) });
        await flushPromises();

        expect(fetchMock.mock.calls[1][0]).toContain('level=cities');
        expect(fetchMock.mock.calls[1][0]).toContain('state=2');
        expect(w.find('[data-test="address-city-select"]').exists()).toBe(true);
    });

    it('clears the state and city when the country changes', () => {
        fetchMock.mockResolvedValue(list(['Jalisco']));
        const w = located({ country: 'MX', stateId: '1', state: 'Jalisco' });

        w.findAllComponents({ name: 'KinetixCombobox' })[0].vm.$emit(
            'update:value',
            'MC',
        );

        expect(lastValue(w)).toEqual({
            country: 'MC',
            state: null,
            stateId: null,
            city: null,
            cityId: null,
        });
    });

    it('lets the user type a level the catalog has nothing for', async () => {
        fetchMock.mockImplementation((url: string) =>
            Promise.resolve(url.includes('level=states') ? list([]) : list([])),
        );

        const w = located({ country: 'MC' });
        await flushPromises();

        // No states → the cities hang from the country, and none either.
        expect(fetchMock.mock.calls[1][0]).not.toContain('state=');
        await w.find('[id$="-state"]').setValue('Monaco');
        expect(lastValue(w)).toMatchObject({ state: 'Monaco', stateId: null });
        expect(w.find('input[id$="-city"]').exists()).toBe(true);
    });

    it("asks a picker's states once, however many pickers want them", async () => {
        fetchMock.mockResolvedValue(list(['Jalisco']));

        located({ country: 'MX' });
        located({ country: 'MX' });
        await flushPromises();

        expect(
            fetchMock.mock.calls.filter(([url]) =>
                url.includes('level=states'),
            ),
        ).toHaveLength(1);
    });
});

describe('KinetixAddressPicker — postal codes', () => {
    const neighborhoods = [
        { value: '10', label: 'Americana', postalCode: '44160' },
        { value: '11', label: 'Lafayette', postalCode: '44150' },
    ];

    const respond = (url: string) => {
        if (url.includes('level=states')) {
            return {
                options: [{ value: '14', label: 'Jalisco' }],
                complete: true,
            };
        }

        if (url.includes('level=cities')) {
            return {
                options: [{ value: '39', label: 'Guadalajara' }],
                complete: true,
            };
        }

        if (url.includes('level=neighborhoods')) {
            return { options: neighborhoods, complete: true };
        }

        return url.includes('code=44160')
            ? {
                  match: {
                      state: { value: '14', label: 'Jalisco' },
                      city: { value: '39', label: 'Guadalajara' },
                      neighborhoods: [neighborhoods[0]],
                  },
              }
            : { match: null };
    };

    // Wired like v-model: what the picker emits comes back as its value.
    const picker = (value: Record<string, unknown>, lookup = true) => {
        const w = mountWith({
            value,
            fields: ['country', 'postalCode', 'state', 'city', 'neighborhood'],
            countries: { MX: 'Mexico' },
            locationsToken: 'tok',
            neighborhoods: true,
            postalCodeLookup: lookup,
            'onUpdate:value': (next: Record<string, unknown>) =>
                w.setProps({ value: next }),
        });

        return w;
    };

    const lastValue = (w: ReturnType<typeof mountWith>) => {
        const events = w.emitted('update:value')!;

        return events[events.length - 1][0] as Record<string, unknown>;
    };

    beforeEach(() => {
        fetchMock.mockReset();
        fetchMock.mockImplementation((url: string) =>
            Promise.resolve(respond(url)),
        );
        clearKinetixLocationsCache();
    });

    it("lists the city's neighborhoods, and picking one fills the postal code", async () => {
        const w = picker(
            {
                country: 'MX',
                stateId: '14',
                state: 'Jalisco',
                cityId: '39',
                city: 'Guadalajara',
            },
            false,
        );
        await flushPromises();

        expect(
            fetchMock.mock.calls.some(([url]) =>
                url.includes('level=neighborhoods&country=MX&state=14&city=39'),
            ),
        ).toBe(true);

        const select = w
            .findAllComponents({ name: 'KinetixCombobox' })
            .find((c) => c.props('id')?.endsWith('-neighborhood'));
        select!.vm.$emit('update:value', '11');

        expect(lastValue(w)).toMatchObject({
            neighborhoodId: '11',
            neighborhood: 'Lafayette',
            postalCode: '44150',
        });
    });

    it('waits for a city before listing neighborhoods', async () => {
        const w = picker({ country: 'MX' }, false);
        await flushPromises();

        expect(
            w.find('[data-test="address-neighborhood-waiting"]').text(),
        ).toContain('Choose a city first');
    });

    it('fills the state, city and only neighborhood from a typed postal code', async () => {
        vi.useFakeTimers();

        try {
            const w = picker({ country: 'MX' });
            await flushPromises();

            await w.find('[id$="-postalCode"]').setValue('44160');
            await vi.advanceTimersByTimeAsync(400);
            await flushPromises();

            expect(
                fetchMock.mock.calls.some(([url]) =>
                    url.includes('level=postal-code&country=MX&code=44160'),
                ),
            ).toBe(true);
            expect(lastValue(w)).toMatchObject({
                postalCode: '44160',
                stateId: '14',
                state: 'Jalisco',
                cityId: '39',
                city: 'Guadalajara',
                neighborhoodId: '10',
                neighborhood: 'Americana',
            });
            expect(
                w.find('[data-test="address-postal-code-status"]').text(),
            ).toBe('Guadalajara, Jalisco. Choose the neighborhood.');
        } finally {
            vi.useRealTimers();
        }
    });

    it('says so when no place has the code, and leaves the levels alone', async () => {
        vi.useFakeTimers();

        try {
            const w = picker({ country: 'MX' });
            await flushPromises();

            await w.find('[id$="-postalCode"]').setValue('99999');
            await vi.advanceTimersByTimeAsync(400);
            await flushPromises();

            expect(lastValue(w)).toEqual({
                country: 'MX',
                postalCode: '99999',
            });
            expect(
                w.find('[data-test="address-postal-code-status"]').text(),
            ).toBe('No place has that postal code.');
        } finally {
            vi.useRealTimers();
        }
    });
});
