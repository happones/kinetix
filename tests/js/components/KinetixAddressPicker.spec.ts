import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

// The country select (KinetixCombobox) reads usePage() at setup.
vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));

import KinetixAddressPicker from '@/components/KinetixAddressPicker.vue';

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
            (w.find('#kx-addr-city').element as HTMLInputElement).value,
        ).toBe('Austin');
    });

    it('honors a restricted/ordered fields list', () => {
        const w = mountWith({ fields: ['city', 'country'] });
        expect(w.findAll('input[type="text"]')).toHaveLength(1); // only city
        expect(w.find('#kx-addr-city').exists()).toBe(true);
        expect(w.find('#kx-addr-line1').exists()).toBe(false);
    });

    it('emits the merged address object on input', async () => {
        const w = mountWith({ value: { city: 'Austin' } });
        const postal = w.find('#kx-addr-postalCode');
        await postal.setValue('73301');

        const events = w.emitted('update:value');
        expect(events).toBeTruthy();
        expect(events![events!.length - 1][0]).toMatchObject({
            city: 'Austin',
            postalCode: '73301',
        });
    });
});
