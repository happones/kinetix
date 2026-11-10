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

    // Kinetix's own types never go out by mail: their Email switch did
    // nothing either way.
    it('shows no switch for a channel the type is not sent on', async () => {
        fetchMock.mockResolvedValueOnce({
            channels: matrix.channels,
            types: [
                {
                    key: 'kinetix.exports',
                    label: 'Finished exports',
                    channels: { database: true },
                },
            ],
        });
        const w = mountIt();
        await flushPromises();

        const boxes = w.findAllComponents(KinetixCheckbox);
        expect(boxes.length).toBe(1);
        expect(
            w.find('[aria-label="Finished exports · In-app"]').exists(),
        ).toBe(true);
        expect(w.find('[aria-label="Finished exports · Email"]').exists()).toBe(
            false,
        );
        expect(w.text()).toContain('kinetix.notification_prefs_not_sent');
    });

    it('skips its heading for a page that already titles the section', async () => {
        const titled = mount(KinetixNotificationPreferences, {
            global: { plugins: [i18n] },
        });
        const bare = mount(KinetixNotificationPreferences, {
            props: { hideTitle: true },
            global: { plugins: [i18n] },
        });
        await flushPromises();

        expect(titled.text()).toContain('notification_prefs_title');
        expect(bare.text()).not.toContain('notification_prefs_title');
        expect(bare.find('h2').exists()).toBe(false);
    });

    describe('mandatory channels', () => {
        const withMandatory = {
            channels: matrix.channels,
            types: [
                {
                    key: 'security',
                    label: 'Security alerts',
                    // The server sends a mandatory channel on.
                    channels: { mail: true, database: true },
                    mandatory: ['mail'],
                },
            ],
        };

        it('renders a mandatory channel checked and aria-disabled, still focusable, with an explanation', async () => {
            fetchMock.mockResolvedValueOnce(withMandatory);
            const w = mountIt();
            await flushPromises();

            const [mail, inApp] = w.findAllComponents(KinetixCheckbox);
            const mailBox = w.get('[aria-label="Security alerts · Email"]');
            expect(mailBox.attributes('aria-disabled')).toBe('true');
            // Not `disabled`: it stays reachable by keyboard and screen reader.
            expect(mailBox.attributes('disabled')).toBeUndefined();
            expect(mail.props('checked')).toBe(true);
            expect(
                w
                    .get('[aria-label="Security alerts · In-app"]')
                    .attributes('aria-disabled'),
            ).toBeUndefined();
            expect(inApp.props('disabled')).toBe(false);

            const hint = w.get('[data-test="pref-mandatory-hint"]');
            expect(hint.text()).toContain(
                'kinetix.notification_routing_prefs_required_on',
            );
            expect(
                w
                    .get('[aria-label="Security alerts · Email"]')
                    .attributes('aria-describedby'),
            ).toBe(hint.attributes('id'));
            expect(
                w
                    .get('[aria-label="Security alerts · In-app"]')
                    .attributes('aria-describedby'),
            ).toBeUndefined();
        });

        it('keeps a mandatory channel on even when an older payload says off', async () => {
            fetchMock.mockResolvedValueOnce({
                ...withMandatory,
                types: [
                    {
                        ...withMandatory.types[0],
                        channels: { mail: false, database: true },
                    },
                ],
            });
            const w = mountIt();
            await flushPromises();

            expect(
                w.findAllComponents(KinetixCheckbox)[0].props('checked'),
            ).toBe(true);
        });

        it('never posts for a mandatory channel, but still does for the others', async () => {
            fetchMock.mockResolvedValueOnce(withMandatory);
            const w = mountIt();
            await flushPromises();
            fetchMock.mockClear();
            fetchMock.mockResolvedValue({ status: 'success' });

            const [mail, inApp] = w.findAllComponents(KinetixCheckbox);
            mail.vm.$emit('change', false);
            await flushPromises();
            expect(fetchMock).not.toHaveBeenCalled();

            // A real click does not change it either.
            await w
                .get('[aria-label="Security alerts · Email"]')
                .trigger('click');
            await flushPromises();
            expect(fetchMock).not.toHaveBeenCalled();
            expect(
                w
                    .get('[aria-label="Security alerts · Email"]')
                    .attributes('data-state'),
            ).toBe('checked');

            inApp.vm.$emit('change', false);
            await flushPromises();
            expect(fetchMock).toHaveBeenCalledTimes(1);
            expect(fetchMock.mock.calls[0][1].body).toMatchObject({
                type: 'security',
                channel: 'database',
                enabled: false,
            });
        });
    });
});
