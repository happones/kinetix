import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));
const fetchMock = vi.fn();
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));

import KinetixNotificationPreferences from '@/components/KinetixNotificationPreferences.vue';
import KinetixCheckbox from '@/components/KinetixCheckbox.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    missing: (_l: string, key: string) => key,
    messages: { en: { kinetix: {} } },
});

const matrix = {
    channels: [
        { key: 'mail', label: 'Email' },
        { key: 'database', label: 'In-app' },
    ],
    types: [
        {
            key: 'orders',
            label: 'Order updates',
            channels: { mail: true, database: true },
        },
        {
            key: 'marketing',
            label: 'Marketing',
            channels: { mail: false, database: true },
        },
    ],
};

const mountIt = () =>
    mount(KinetixNotificationPreferences, { global: { plugins: [i18n] } });

describe('KinetixNotificationPreferences', () => {
    it('renders a row per type and a column per channel', async () => {
        fetchMock.mockResolvedValueOnce(matrix);
        const w = mountIt();
        await flushPromises();

        expect(w.text()).toContain('Order updates');
        expect(w.text()).toContain('Marketing');
        expect(w.text()).toContain('Email');
        // 2 types × 2 channels = 4 checkboxes.
        expect(w.findAllComponents(KinetixCheckbox).length).toBe(4);
    });

    it('persists a toggle with type + channel + enabled', async () => {
        fetchMock.mockResolvedValueOnce(matrix);
        const w = mountIt();
        await flushPromises();

        fetchMock.mockResolvedValueOnce({ status: 'success' });
        // First checkbox = orders/mail (currently on) → toggle off.
        await w.findAllComponents(KinetixCheckbox)[0].vm.$emit('change', false);

        const postCall = fetchMock.mock.calls.find(
            (c) => c[1]?.method === 'POST',
        );
        expect(postCall).toBeTruthy();
        expect(postCall![1].body).toMatchObject({
            type: 'orders',
            channel: 'mail',
            enabled: false,
        });
    });
});
