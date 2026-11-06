import { flushPromises } from '@vue/test-utils';
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

/** A fetch Response stand-in kinetixFetch can read. */
function jsonResponse(body: unknown, status = 200): Response {
    const text = body === null ? '' : JSON.stringify(body);

    return {
        ok: status >= 200 && status < 300,
        status,
        text: async () => text,
        json: async () => JSON.parse(text),
        clone() {
            return this;
        },
    } as unknown as Response;
}

function item(id: string, title: string) {
    return {
        id,
        title,
        status: 'info',
        read: false,
        created_at: '2026-11-05T00:00:00Z',
    };
}
import {
    toastDuration,
    useNotificationsStore,
} from '@/stores/kinetixNotifications';

describe('notifications store — sendRequest (database mode)', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        reload.mockReset();
        document.cookie = 'XSRF-TOKEN=test-token';
    });

    it("sends the delete with JSON Accept + same-origin credentials so auth/CSRF failures aren't silently followed", async () => {
        const fetchMock = vi.fn().mockResolvedValue(jsonResponse(null));
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

        // The follow-up feed read, before the real fetch comes back.
        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        await flushPromises();
        vi.unstubAllGlobals();
    });

    it('re-reads the list from the JSON feed after a successful request', async () => {
        const fetchMock = vi
            .fn()
            .mockResolvedValueOnce(jsonResponse(null))
            .mockResolvedValueOnce(
                jsonResponse({ notifications: [item('b', 'Kept')] }),
            );
        vi.stubGlobal('fetch', fetchMock);

        const store = useNotificationsStore();
        store.markAllAsRead();

        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        await flushPromises();

        const [url, init] = fetchMock.mock.calls[1];
        expect(url).toBe('/_kinetix/notifications');
        expect(init.method).toBe('GET');
        expect(store.notifications.map((n) => n.id)).toEqual(['b']);
        expect(reload).not.toHaveBeenCalled();

        vi.unstubAllGlobals();
    });

    it('rolls the optimistic removal back from the feed when the request fails', async () => {
        const fetchMock = vi
            .fn()
            .mockResolvedValueOnce(jsonResponse({ message: 'Expired' }, 419))
            .mockResolvedValueOnce(
                jsonResponse({ notifications: [item('abc-123', 'Back')] }),
            );
        vi.stubGlobal('fetch', fetchMock);
        vi.spyOn(console, 'error').mockImplementation(() => {});

        const store = useNotificationsStore();
        store.syncFromProps([item('abc-123', 'Back')]);
        store.removeNotification('abc-123');
        expect(store.notifications).toHaveLength(0);

        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        await flushPromises();

        expect(fetchMock.mock.calls[1][0]).toBe('/_kinetix/notifications');
        expect(store.notifications.map((n) => n.id)).toEqual(['abc-123']);
        expect(reload).not.toHaveBeenCalled();

        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('refresh() settles and keeps the list when the feed fails', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockRejectedValue(new TypeError('offline')),
        );
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const store = useNotificationsStore();
        store.syncFromProps([item('a', 'Still here')]);

        await expect(store.refresh()).resolves.toBeUndefined();
        expect(store.notifications.map((n) => n.id)).toEqual(['a']);
        expect(warn).toHaveBeenCalled();

        vi.unstubAllGlobals();
        vi.restoreAllMocks();
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

describe('notifications store — sound', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('swallows the rejection when the browser blocks autoplay', () => {
        const blocked = Promise.reject(new Error('NotAllowedError'));
        const handled = vi.spyOn(blocked, 'catch');
        const play = vi.fn(() => blocked);
        vi.stubGlobal(
            'Audio',
            class {
                play = play;
            },
        );
        pageProps.kinetix_config = {
            ...(pageProps.kinetix_config as object),
            sound: { enabled: true, path: '/ding.mp3' },
        };

        const store = useNotificationsStore();
        store.syncFromProps([]);
        store.isInitialized = true;
        store.syncFromProps([
            {
                id: 'n1',
                title: 'New',
                status: 'success',
                read: false,
                created_at: '2026-10-29T00:00:00Z',
            },
        ]);

        expect(play).toHaveBeenCalledTimes(1);
        // Without a .catch() the rejection is an uncaught error per toast.
        expect(handled).toHaveBeenCalled();

        delete (pageProps.kinetix_config as Record<string, unknown>).sound;
        vi.unstubAllGlobals();
    });
});

describe('notifications store — toast duration', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('keeps a persistent() notification (duration null) on screen until closed', () => {
        useNotificationsStore().triggerToast({
            id: 'p',
            title: 'Backup failed',
            status: 'danger',
            duration: null,
            created_at: '2026-10-05T00:00:00Z',
        });

        expect(toast.error).toHaveBeenCalledWith(
            'Backup failed',
            expect.objectContaining({ duration: Number.POSITIVE_INFINITY }),
        );
    });

    it('uses the given duration, and the default only when none is given', () => {
        expect(toastDuration(6000)).toBe(6000);
        expect(toastDuration(undefined)).toBe(4000);
        expect(toastDuration(0)).toBe(4000);
        expect(toastDuration(null)).toBe(Number.POSITIVE_INFINITY);
    });
});
