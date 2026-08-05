import { beforeEach, describe, expect, it, vi } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';

const reload = vi.fn();
const pageProps: Record<string, unknown> = {
    kinetix_config: { database: true, route_prefix: '_kinetix' },
    kinetix_notifications: [],
    auth: { user: { id: 1 } },
};

vi.mock('@inertiajs/vue3', () => ({
    router: { reload: (...args: unknown[]) => reload(...args) },
    usePage: () => ({ props: pageProps }),
}));

vi.mock('vue-sonner', () => ({
    toast: {
        success: vi.fn(),
        warning: vi.fn(),
        error: vi.fn(),
        info: vi.fn(),
    },
}));

import { toast } from 'vue-sonner';
import { useNotificationsStore } from '@/stores/kinetixNotifications';

describe('notifications store — sendRequest (database mode)', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        reload.mockReset();
        document.cookie = 'XSRF-TOKEN=test-token';
    });

    it("sends the delete with JSON Accept + same-origin credentials so auth/CSRF failures aren't silently followed", async () => {
        const fetchMock = vi
            .fn()
            .mockResolvedValue({ ok: true, status: 200 } as Response);
        vi.stubGlobal('fetch', fetchMock);

        const store = useNotificationsStore();
        store.removeNotification('abc-123');

        // Let the awaited fetch resolve.
        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalled());

        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/_kinetix/notifications/abc-123');
        expect(init.method).toBe('DELETE');
        expect(init.credentials).toBe('same-origin');
        expect(init.headers.Accept).toBe('application/json');
        expect(init.headers['X-XSRF-TOKEN']).toBe('test-token');

        vi.unstubAllGlobals();
    });

    it('re-syncs from the server when the request fails', async () => {
        const fetchMock = vi
            .fn()
            .mockResolvedValue({ ok: false, status: 419 } as Response);
        vi.stubGlobal('fetch', fetchMock);

        const store = useNotificationsStore();
        store.removeNotification('abc-123');

        await vi.waitFor(() => expect(reload).toHaveBeenCalled());
        expect(reload.mock.calls[0][0]).toMatchObject({
            only: ['kinetix_notifications'],
        });

        vi.unstubAllGlobals();
    });
});

describe('notifications store — syncFromProps (database mode)', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('stays silent on the initial sync, then toasts only newly arrived unread items (polling)', () => {
        const store = useNotificationsStore();

        // Initial page load: persisted rows are old news — no toast.
        store.syncFromProps([
            {
                id: 'a',
                title: 'Old',
                status: 'success',
                read: false,
                created_at: '2026-08-04T00:00:00Z',
            },
        ]);
        expect(toast.success).not.toHaveBeenCalled();

        // A later poll brings one genuinely new unread item, one already-seen
        // item and one that arrived already read.
        store.isInitialized = true;
        store.syncFromProps([
            {
                id: 'b',
                title: 'New',
                status: 'success',
                read: false,
                created_at: '2026-08-04T00:01:00Z',
            },
            {
                id: 'c',
                title: 'Already read',
                status: 'info',
                read: true,
                created_at: '2026-08-04T00:01:00Z',
            },
            {
                id: 'a',
                title: 'Old',
                status: 'success',
                read: false,
                created_at: '2026-08-04T00:00:00Z',
            },
        ]);

        expect(toast.success).toHaveBeenCalledTimes(1);
        expect(toast.success).toHaveBeenCalledWith('New', expect.anything());
        expect(toast.info).not.toHaveBeenCalled();
        expect(store.notifications).toHaveLength(3);
    });
});
