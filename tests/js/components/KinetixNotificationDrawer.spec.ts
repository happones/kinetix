import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({
    router: { reload: vi.fn() },
    usePage: () => ({
        props: {
            kinetix_config: { database: true, route_prefix: '_kinetix' },
            kinetix_notifications: [],
            auth: { user: { id: 1 } },
        },
    }),
}));

vi.mock('vue-sonner', () => ({
    toast: {
        success: vi.fn(),
        warning: vi.fn(),
        error: vi.fn(),
        info: vi.fn(),
    },
}));

import KinetixNotificationDrawer from '@/components/KinetixNotificationDrawer.vue';
import { useNotificationsStore } from '@/stores/kinetixNotifications';
import { i18n } from './i18n';

afterEach(() => {
    document.body.innerHTML = '';
});

describe('KinetixNotificationDrawer', () => {
    it('names its close button in the reader’s language, with a real target', async () => {
        setActivePinia(createPinia());
        useNotificationsStore().toggleDrawer();

        mount(KinetixNotificationDrawer, {
            attachTo: document.body,
            global: { plugins: [i18n] },
        });
        await flushPromises();

        const close = document.querySelector(
            'button[aria-label="Close"]',
        ) as HTMLButtonElement | null;

        expect(close).not.toBeNull();
        expect(close!.type).toBe('button');
        expect(close!.className).toContain('size-8');
        expect(document.querySelector('[aria-label="Cerrar"]')).toBeNull();
    });
});
